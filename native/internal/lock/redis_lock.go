package lock

import (
	"context"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

type RedisLock struct {
	client *redis.Client
	name   string
	owner  string
	ttl    time.Duration
}

func DistributedWorkerLockName(job string) string {
	return "nntmux:distributed-worker:" + job
}

func DistributedWorkerRedisKey(logicalName string, cachePrefix string, redisPrefix string) string {
	return redisPrefix + cachePrefix + logicalName
}

func NewRedisLock(client *redis.Client, name string, owner string, ttl time.Duration) RedisLock {
	return RedisLock{
		client: client,
		name:   name,
		owner:  owner,
		ttl:    ttl,
	}
}

func (lock RedisLock) TryAcquire(ctx context.Context) (bool, error) {
	if err := lock.validate(); err != nil {
		return false, err
	}

	return lock.client.SetNX(ctx, lock.name, lock.owner, lock.ttl).Result()
}

func (lock RedisLock) Release(ctx context.Context) (bool, error) {
	if err := lock.validate(); err != nil {
		return false, err
	}

	const releaseIfOwner = `
		if redis.call("get", KEYS[1]) == ARGV[1] then
			return redis.call("del", KEYS[1])
		end
		return 0
	`

	result, err := lock.client.Eval(ctx, releaseIfOwner, []string{lock.name}, lock.owner).Int()
	if err != nil {
		return false, err
	}

	return result == 1, nil
}

func (lock RedisLock) IsHeldByOwner(ctx context.Context) (bool, error) {
	if err := lock.validate(); err != nil {
		return false, err
	}

	owner, err := lock.client.Get(ctx, lock.name).Result()
	if err == redis.Nil {
		return false, nil
	}
	if err != nil {
		return false, err
	}

	return owner == lock.owner, nil
}

func (lock RedisLock) validate() error {
	if lock.name == "" {
		return fmt.Errorf("redis key is required")
	}

	if lock.owner == "" {
		return fmt.Errorf("owner is required")
	}

	if lock.ttl <= 0 {
		return fmt.Errorf("ttl must be positive")
	}

	return nil
}
