#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

python3 - "$@" <<'PY'
import argparse
import base64
import json
import os
import subprocess
import sys
from pathlib import Path

NNTP_KEYS = [
    "NNTP_COMPRESSED_HEADERS",
    "USE_ALTERNATE_NNTP_SERVER",
    "NNTP_USERNAME",
    "NNTP_PASSWORD",
    "NNTP_SERVER",
    "NNTP_PORT",
    "NNTP_CONNECTIONS",
    "NNTP_SSLENABLED",
    "NNTP_SOCKET_TIMEOUT",
    "NNTP_CONNECT_TIMEOUT",
    "NNTP_USERNAME_A",
    "NNTP_PASSWORD_A",
    "NNTP_SERVER_A",
    "NNTP_PORT_A",
    "NNTP_CONNECTIONS_A",
    "NNTP_SSLENABLED_A",
    "NNTP_SOCKET_TIMEOUT_A",
    "NNTP_CONNECT_TIMEOUT_A",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Sync or check .env.native-eval NNTP values from a k3s deployment "
            "without printing secret values. --mode check|apply"
        )
    )
    parser.add_argument("--mode", choices=["check", "apply"], default="check")
    parser.add_argument("--env-file", default=os.environ.get("NNTMUX_NATIVE_EVAL_ENV_FILE", ".env.native-eval"))
    parser.add_argument("--kubeconfig", default=os.environ.get("KUBECONFIG", str(Path.home() / "k3s.yaml")))
    parser.add_argument("--namespace", default=os.environ.get("NNTMUX_K8S_NAMESPACE", "media"))
    parser.add_argument("--deployment", default=os.environ.get("NNTMUX_K8S_DEPLOYMENT", "nntmux-web"))
    parser.add_argument("--selector", default=os.environ.get("NNTMUX_K8S_SELECTOR", ""))
    return parser.parse_args()


def kubectl(args: list[str], options: argparse.Namespace) -> dict:
    command = ["kubectl", "--kubeconfig", options.kubeconfig, "-n", options.namespace, *args, "-o", "json"]
    try:
        result = subprocess.run(command, check=True, text=True, capture_output=True)
    except FileNotFoundError:
        raise SystemExit("kubectl is required to sync NNTP settings from k3s.")
    except subprocess.CalledProcessError as exc:
        detail = (exc.stderr or exc.stdout or "").strip().splitlines()
        message = detail[-1] if detail else "kubectl command failed"
        raise SystemExit(f"Unable to read k3s resources from namespace {options.namespace}: {message}")

    return json.loads(result.stdout)


def load_secret(name: str, options: argparse.Namespace, cache: dict[str, dict[str, str]]) -> dict[str, str]:
    if name in cache:
        return cache[name]

    raw = kubectl(["get", "secret", name], options)
    values: dict[str, str] = {}
    for key, encoded in (raw.get("data") or {}).items():
        values[key] = base64.b64decode(encoded).decode()
    cache[name] = values
    return values


def load_configmap(name: str, options: argparse.Namespace, cache: dict[str, dict[str, str]]) -> dict[str, str]:
    if name in cache:
        return cache[name]

    raw = kubectl(["get", "configmap", name], options)
    values = {key: str(value) for key, value in (raw.get("data") or {}).items()}
    cache[name] = values
    return values


def deployment_items(options: argparse.Namespace) -> list[dict]:
    if options.deployment:
        return [kubectl(["get", "deployment", options.deployment], options)]

    command = ["get", "deployment"]
    if options.selector:
        command.extend(["-l", options.selector])

    return kubectl(command, options).get("items", [])


def collect_values(options: argparse.Namespace) -> tuple[dict[str, str], dict[str, str]]:
    secrets: dict[str, dict[str, str]] = {}
    configmaps: dict[str, dict[str, str]] = {}
    values: dict[str, str] = {}
    sources: dict[str, str] = {}

    for deployment in deployment_items(options):
        deployment_name = deployment.get("metadata", {}).get("name", "unknown")
        pod_spec = deployment.get("spec", {}).get("template", {}).get("spec", {})
        for container in pod_spec.get("containers", []):
            container_name = container.get("name", "container")

            for env_from in container.get("envFrom", []) or []:
                if "secretRef" in env_from:
                    name = env_from["secretRef"].get("name", "")
                    data = load_secret(name, options, secrets) if name else {}
                    for key in NNTP_KEYS:
                        if key in data:
                            values[key] = data[key]
                            sources[key] = f"{deployment_name}/{container_name}:envFrom secret/{name}/{key}"
                if "configMapRef" in env_from:
                    name = env_from["configMapRef"].get("name", "")
                    data = load_configmap(name, options, configmaps) if name else {}
                    for key in NNTP_KEYS:
                        if key in data:
                            values[key] = data[key]
                            sources[key] = f"{deployment_name}/{container_name}:envFrom configmap/{name}/{key}"

            for env in container.get("env", []) or []:
                key = env.get("name", "")
                if key not in NNTP_KEYS:
                    continue

                if "value" in env:
                    values[key] = str(env["value"])
                    sources[key] = f"{deployment_name}/{container_name}:env/{key}"
                    continue

                value_from = env.get("valueFrom") or {}
                if "secretKeyRef" in value_from:
                    ref = value_from["secretKeyRef"]
                    name = ref.get("name", "")
                    data = load_secret(name, options, secrets) if name else {}
                    if ref.get("key") in data:
                        values[key] = data[ref["key"]]
                        sources[key] = f"{deployment_name}/{container_name}:secretKeyRef/{name}/{ref.get('key')}"
                if "configMapKeyRef" in value_from:
                    ref = value_from["configMapKeyRef"]
                    name = ref.get("name", "")
                    data = load_configmap(name, options, configmaps) if name else {}
                    if ref.get("key") in data:
                        values[key] = data[ref["key"]]
                        sources[key] = f"{deployment_name}/{container_name}:configMapKeyRef/{name}/{ref.get('key')}"

    selected = {key: values[key] for key in NNTP_KEYS if key in values}
    if not selected:
        raise SystemExit(
            "No NNTP_* values were found in k3s deployments. "
            "Set --deployment or --selector if the media deployment has a non-obvious name."
        )

    return selected, sources


def read_env(path: Path) -> tuple[list[str], dict[str, str]]:
    if not path.exists():
        raise SystemExit(f"Missing env file: {path}")

    lines = path.read_text().splitlines()
    values: dict[str, str] = {}
    for line in lines:
        if not line or line.lstrip().startswith("#") or "=" not in line:
            continue
        key, raw = line.split("=", 1)
        values[key] = raw
    return lines, values


def write_env(path: Path, lines: list[str], desired: dict[str, str]) -> None:
    remaining = dict(desired)
    output: list[str] = []

    for line in lines:
        if line and not line.lstrip().startswith("#") and "=" in line:
            key, _ = line.split("=", 1)
            if key in remaining:
                output.append(f"{key}={remaining.pop(key)}")
                continue
        output.append(line)

    if remaining:
        if output and output[-1] != "":
            output.append("")
        output.append("# NNTP values synced from k3s media deployment; values intentionally not printed by helper.")
        for key in NNTP_KEYS:
            if key in remaining:
                output.append(f"{key}={remaining.pop(key)}")

    path.write_text("\n".join(output) + "\n")


def main() -> int:
    options = parse_args()
    env_path = Path(options.env_file)
    desired, sources = collect_values(options)
    lines, current = read_env(env_path)

    mismatched = [key for key, value in desired.items() if current.get(key) != value]
    missing = [key for key in desired if key not in current]

    if options.mode == "check":
        if mismatched:
            print(f"NNTP k3s check failed for {len(mismatched)} redacted key(s): {', '.join(mismatched)}", file=sys.stderr)
            if missing:
                print(f"Missing redacted key(s): {', '.join(missing)}", file=sys.stderr)
            return 1
        print(f"NNTP k3s check passed for {len(desired)} redacted key(s): {', '.join(desired.keys())}")
        return 0

    write_env(env_path, lines, desired)
    print(f"NNTP k3s sync applied {len(desired)} redacted key(s) to {env_path}: {', '.join(desired.keys())}")
    print(f"Sources inspected: {len(set(sources.values()))} redacted deployment/env reference(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
PY
