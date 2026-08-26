package lock

import (
	"context"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/redis/go-redis/v9"
)

func TestRedisLockRoundTripUsesDistributedWorkerLockName(t *testing.T) {
	addr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if addr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native Redis integration tests")
	}

	ctx := context.Background()
	client := redis.NewClient(&redis.Options{Addr: addr})
	defer client.Close()

	logicalName := DistributedWorkerLockName("metadata-refresh")
	if logicalName != "nntmux:distributed-worker:metadata-refresh" {
		t.Fatalf("lock name = %q", logicalName)
	}
	lockName := DistributedWorkerRedisKey(logicalName, "nntmux-cache-", "nntmux_database_")
	if lockName != "nntmux_database_nntmux-cache-nntmux:distributed-worker:metadata-refresh" {
		t.Fatalf("redis lock key = %q", lockName)
	}

	_ = client.Del(ctx, lockName).Err()

	first := NewRedisLock(client, lockName, "owner-a", 5*time.Second)
	acquired, err := first.TryAcquire(ctx)
	if err != nil {
		t.Fatalf("first acquire: %v", err)
	}
	if !acquired {
		t.Fatalf("first acquire = false, want true")
	}

	second := NewRedisLock(client, lockName, "owner-b", 5*time.Second)
	acquired, err = second.TryAcquire(ctx)
	if err != nil {
		t.Fatalf("second acquire: %v", err)
	}
	if acquired {
		t.Fatalf("second acquire = true while first owner holds lock")
	}

	released, err := second.Release(ctx)
	if err != nil {
		t.Fatalf("non-owner release: %v", err)
	}
	if released {
		t.Fatalf("non-owner release = true, want false")
	}

	released, err = first.Release(ctx)
	if err != nil {
		t.Fatalf("release: %v", err)
	}
	if !released {
		t.Fatalf("release = false, want true")
	}

	acquired, err = second.TryAcquire(ctx)
	if err != nil {
		t.Fatalf("second acquire after release: %v", err)
	}
	if !acquired {
		t.Fatalf("second acquire after release = false, want true")
	}

	_, _ = second.Release(ctx)
}

func TestRedisLockRejectsInvalidOwnerAndTTL(t *testing.T) {
	ctx := context.Background()
	client := redis.NewClient(&redis.Options{Addr: "127.0.0.1:1"})
	defer client.Close()

	tests := []struct {
		name string
		lock RedisLock
		want string
	}{
		{
			name: "empty owner",
			lock: NewRedisLock(client, "key", "", 5*time.Second),
			want: "owner is required",
		},
		{
			name: "empty key",
			lock: NewRedisLock(client, "", "owner-a", 5*time.Second),
			want: "redis key is required",
		},
		{
			name: "nonpositive ttl",
			lock: NewRedisLock(client, "key", "owner-a", 0),
			want: "ttl must be positive",
		},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if _, err := test.lock.TryAcquire(ctx); err == nil || !strings.Contains(err.Error(), test.want) {
				t.Fatalf("TryAcquire error = %v, want %q", err, test.want)
			}
		})
	}
}

func TestRedisLockDetectsHeldOwnerWithoutAcquiring(t *testing.T) {
	addr := os.Getenv("NNTMUX_NATIVE_REDIS_ADDR")
	if addr == "" {
		t.Skip("NNTMUX_NATIVE_REDIS_ADDR is required for native Redis integration tests")
	}

	ctx := context.Background()
	client := redis.NewClient(&redis.Options{Addr: addr})
	defer client.Close()

	lockName := "nntmux_database_nntmux-cache-nntmux:distributed-worker:held-owner-test"
	_ = client.Del(ctx, lockName).Err()
	defer client.Del(ctx, lockName)

	if err := client.Set(ctx, lockName, "php-owner", 5*time.Second).Err(); err != nil {
		t.Fatalf("seed lock owner: %v", err)
	}

	held, err := NewRedisLock(client, lockName, "php-owner", 5*time.Second).IsHeldByOwner(ctx)
	if err != nil {
		t.Fatalf("IsHeldByOwner matching owner: %v", err)
	}
	if !held {
		t.Fatalf("IsHeldByOwner matching owner = false, want true")
	}

	held, err = NewRedisLock(client, lockName, "other-owner", 5*time.Second).IsHeldByOwner(ctx)
	if err != nil {
		t.Fatalf("IsHeldByOwner mismatched owner: %v", err)
	}
	if held {
		t.Fatalf("IsHeldByOwner mismatched owner = true, want false")
	}

	if err := client.Del(ctx, lockName).Err(); err != nil {
		t.Fatalf("delete lock: %v", err)
	}

	held, err = NewRedisLock(client, lockName, "php-owner", 5*time.Second).IsHeldByOwner(ctx)
	if err != nil {
		t.Fatalf("IsHeldByOwner missing lock: %v", err)
	}
	if held {
		t.Fatalf("IsHeldByOwner missing lock = true, want false")
	}
}
