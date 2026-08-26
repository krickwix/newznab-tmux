package fixnames

import (
	"bytes"
	"fmt"
	"sort"
	"strconv"
	"strings"

	"nntmux-native/internal/worker"
)

type Plan struct {
	Commands             int                  `json:"commands"`
	Methods              int                  `json:"methods"`
	Categories           int                  `json:"categories"`
	LimitedCommands      int                  `json:"limited_commands"`
	UpdateCommands       int                  `json:"update_commands"`
	SetStatusCommands    int                  `json:"set_status_commands"`
	ShowCommands         int                  `json:"show_commands"`
	CRCMutations         int                  `json:"crc_mutations,omitempty"`
	CRCStatusOnly        int                  `json:"crc_status_only,omitempty"`
	ParHashMutations     int                  `json:"par_hash_mutations,omitempty"`
	ParHashStatusOnly    int                  `json:"par_hash_status_only,omitempty"`
	ReplacementReady     bool                 `json:"replacement_ready"`
	ReplacementReadiness ReplacementReadiness `json:"replacement_readiness"`
	Writes               int                  `json:"writes"`
}

type ReplacementReadiness struct {
	SupportedMethods    []string `json:"supported_methods"`
	UnsupportedMethods  []string `json:"unsupported_methods"`
	UnsupportedCommands int      `json:"unsupported_commands"`
	NativeCommands      int      `json:"native_commands"`
	Blockers            []string `json:"blockers"`
}

func BuildPlan(plan worker.Plan) (Plan, error) {
	if plan.Job.Name != "fixnames" {
		return Plan{}, fmt.Errorf("fixnames planner requires job %q", "fixnames")
	}

	methods := map[string]bool{}
	supportedMethods := map[string]bool{}
	unsupportedMethods := map[string]bool{}
	categories := map[string]bool{}
	nativeCommands := 0
	result := Plan{
		Commands: len(plan.Commands),
		Writes:   0,
	}

	for _, command := range plan.Commands {
		if command.Command != "releases:fix-names" {
			return Plan{}, fmt.Errorf("unsupported fixnames command %q in native dry-run planner", command.Command)
		}

		arguments, ok := command.Arguments.(map[string]any)
		if !ok {
			return Plan{}, fmt.Errorf("fixnames command arguments must be an object")
		}

		method, ok := methodArgument(arguments["method"])
		if !ok {
			return Plan{}, fmt.Errorf("fixnames command method is required")
		}
		methods[method] = true
		if isNativeDiscoveryMethod(method) {
			supportedMethods[method] = true
			nativeCommands++
		} else {
			unsupportedMethods[method] = true
		}

		category, ok := arguments["--category"].(string)
		if !ok {
			return Plan{}, fmt.Errorf("fixnames command category is required")
		}
		if category != "other" && category != "movies" {
			return Plan{}, fmt.Errorf("unsupported fixnames category %q in native dry-run planner", category)
		}
		categories[category] = true

		if boolArgument(arguments["--update"], false) {
			result.UpdateCommands++
		}
		if boolArgument(arguments["--set-status"], false) {
			result.SetStatusCommands++
		}
		if boolArgument(arguments["--show"], false) {
			result.ShowCommands++
		}
		if hasPositiveLimit(arguments["--limit"]) {
			result.LimitedCommands++
		}
	}

	supportedMethodList := sortedMethodKeys(supportedMethods)
	unsupportedMethodList := sortedMethodKeys(unsupportedMethods)
	allMethods := sortedMethodKeys(methods)
	result.Methods = len(allMethods)
	result.Categories = len(categories)
	result.ReplacementReady = false
	result.ReplacementReadiness = ReplacementReadiness{
		SupportedMethods:    supportedMethodList,
		UnsupportedMethods:  unsupportedMethodList,
		UnsupportedCommands: len(plan.Commands) - nativeCommands,
		NativeCommands:      nativeCommands,
		Blockers: []string{
			fmt.Sprintf("unsupported regular fix-name methods: %s", strings.Join(unsupportedMethodList, ",")),
			"remaining regular fix-name methods are deferred to PHP",
			"release rename, category, event, and search side effects remain PHP-owned",
		},
	}

	return result, nil
}

func DryRunSummary(plan Plan) string {
	var buffer bytes.Buffer

	fmt.Fprintln(&buffer, "fixnames dry-run")
	fmt.Fprintf(&buffer, "commands=%d\n", plan.Commands)
	fmt.Fprintf(&buffer, "methods=%d\n", plan.Methods)
	fmt.Fprintf(&buffer, "categories=%d\n", plan.Categories)
	fmt.Fprintf(&buffer, "limited-commands=%d\n", plan.LimitedCommands)
	fmt.Fprintf(&buffer, "update-commands=%d\n", plan.UpdateCommands)
	fmt.Fprintf(&buffer, "set-status-commands=%d\n", plan.SetStatusCommands)
	fmt.Fprintf(&buffer, "show-commands=%d\n", plan.ShowCommands)
	if plan.CRCMutations > 0 || plan.CRCStatusOnly > 0 || plan.ParHashMutations > 0 || plan.ParHashStatusOnly > 0 {
		fmt.Fprintf(&buffer, "crc-mutations=%d\n", plan.CRCMutations)
		fmt.Fprintf(&buffer, "crc-status-only=%d\n", plan.CRCStatusOnly)
		fmt.Fprintf(&buffer, "par-hash-mutations=%d\n", plan.ParHashMutations)
		fmt.Fprintf(&buffer, "par-hash-status-only=%d\n", plan.ParHashStatusOnly)
	}
	fmt.Fprintf(&buffer, "replacement-ready=%t\n", plan.ReplacementReady)
	fmt.Fprintf(&buffer, "writes=%d\n", plan.Writes)

	return buffer.String()
}

func isNativeDiscoveryMethod(method string) bool {
	return method == "15" || method == "19"
}

func methodArgument(value any) (string, bool) {
	switch value := value.(type) {
	case string:
		value = strings.TrimSpace(value)
		return value, value != ""
	case float64:
		if value < 0 {
			return "", false
		}
		return strconv.Itoa(int(value)), true
	default:
		return "", false
	}
}

func boolArgument(value any, fallback bool) bool {
	switch value := value.(type) {
	case bool:
		return value
	case string:
		parsed, err := strconv.ParseBool(value)
		if err == nil {
			return parsed
		}
	}

	return fallback
}

func hasPositiveLimit(value any) bool {
	switch value := value.(type) {
	case float64:
		return value > 0
	case string:
		limit, err := strconv.Atoi(strings.TrimSpace(value))
		return err == nil && limit > 0
	default:
		return false
	}
}

func sortedMethodKeys(methods map[string]bool) []string {
	keys := make([]string, 0, len(methods))
	for method := range methods {
		keys = append(keys, method)
	}
	sort.Slice(keys, func(i, j int) bool {
		left, leftErr := strconv.Atoi(keys[i])
		right, rightErr := strconv.Atoi(keys[j])
		if leftErr == nil && rightErr == nil {
			return left < right
		}

		return keys[i] < keys[j]
	})

	return keys
}
