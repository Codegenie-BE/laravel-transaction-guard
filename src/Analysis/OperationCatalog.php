<?php

declare(strict_types=1);

namespace Codegenie\TransactionGuard\Analysis;

final class OperationCatalog
{
    public const CACHE_MUTATIONS = [
        'put', 'set', 'putMany', 'setMultiple', 'add', 'forever', 'remember', 'rememberWithWarmth',
        'rememberForever', 'sear', 'flexible', 'touch', 'forget', 'delete', 'deleteMultiple', 'clear',
        'flush', 'flushLocks', 'increment', 'decrement', 'pull', 'withoutOverlapping',
    ];

    public const CACHE_LOCK_TERMINALS = ['get', 'block', 'release', 'forceRelease'];

    public const RATE_LIMITER_MUTATIONS = ['attempt', 'hit', 'increment', 'decrement', 'clear', 'resetAttempts'];

    public const REDIS_MUTATIONS = [
        'set', 'setex', 'psetex', 'mset', 'del', 'unlink', 'incr', 'incrby', 'incrbyfloat', 'decr',
        'decrby', 'hset', 'hmset', 'hdel', 'hincrby', 'lpush', 'rpush', 'lpop', 'rpop', 'ltrim',
        'sadd', 'srem', 'smove', 'zadd', 'zincrby', 'zrem', 'expire', 'pexpire', 'persist', 'flushdb',
        'flushall', 'publish', 'xadd', 'xdel', 'xtrim', 'pfadd', 'pfmerge', 'setbit', 'bitop', 'geoadd',
    ];

    public const REDIS_MUTATING_COMMANDS = [
        'SET', 'SETEX', 'PSETEX', 'MSET', 'DEL', 'UNLINK', 'INCR', 'INCRBY', 'INCRBYFLOAT', 'DECR',
        'DECRBY', 'HSET', 'HMSET', 'HDEL', 'HINCRBY', 'LPUSH', 'RPUSH', 'LPOP', 'RPOP', 'LTRIM',
        'SADD', 'SREM', 'SMOVE', 'ZADD', 'ZINCRBY', 'ZREM', 'EXPIRE', 'PEXPIRE', 'PERSIST', 'FLUSHDB',
        'FLUSHALL', 'PUBLISH', 'XADD', 'XDEL', 'XTRIM', 'PFADD', 'PFMERGE', 'SETBIT', 'BITOP', 'GEOADD',
    ];

    public const REDIS_READ_COMMANDS = [
        'GET', 'MGET', 'EXISTS', 'TTL', 'PTTL', 'HGET', 'HGETALL', 'HMGET', 'HLEN', 'LRANGE', 'LLEN',
        'SCARD', 'SMEMBERS', 'SISMEMBER', 'ZRANGE', 'ZREVRANGE', 'ZSCORE', 'ZCARD', 'XRANGE', 'XREVRANGE',
        'XLEN', 'PFCOUNT', 'GETBIT', 'BITCOUNT', 'GEODIST', 'GEOHASH', 'GEOPOS', 'TYPE', 'PING',
    ];

    public const REDIS_SCRIPT_COMMANDS = ['EVAL', 'EVALSHA', 'FCALL'];

    public const QUERY_MUTATIONS = [
        'insert', 'insertGetId', 'insertOrIgnore', 'insertUsing', 'update', 'updateOrInsert', 'upsert',
        'delete', 'truncate', 'increment', 'decrement', 'incrementEach', 'decrementEach', 'statement',
        'unprepared', 'affectingStatement',
    ];

    public const ELOQUENT_STATIC_MUTATIONS = [
        'create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'upsert', 'insert', 'insertOrIgnore',
        'update', 'delete', 'destroy', 'truncate', 'increment', 'decrement', 'incrementEach', 'decrementEach',
        'forceDelete', 'restore',
    ];

    public const ELOQUENT_INSTANCE_MUTATIONS = [
        'save', 'saveQuietly', 'update', 'updateQuietly', 'delete', 'deleteQuietly', 'forceDelete',
        'forceDeleteQuietly', 'restore', 'restoreQuietly', 'touch', 'touchQuietly', 'push', 'pushQuietly',
        'increment', 'decrement',
    ];

    public const RELATION_MUTATIONS = [
        'attach', 'detach', 'sync', 'syncWithoutDetaching', 'syncWithPivotValues', 'toggle',
        'updateExistingPivot', 'save', 'saveMany', 'create', 'createMany', 'updateOrCreate', 'firstOrCreate',
    ];

    /** @param list<string> $values */
    public static function alternation(array $values): string
    {
        return implode('|', array_map(static fn (string $value): string => preg_quote($value, '/'), $values));
    }

    public static function redisCommandKind(string $command): string
    {
        $command = strtoupper($command);
        if (in_array($command, self::REDIS_MUTATING_COMMANDS, true)) {
            return 'mutation';
        }
        if (in_array($command, self::REDIS_READ_COMMANDS, true)) {
            return 'read';
        }
        if (in_array($command, self::REDIS_SCRIPT_COMMANDS, true)) {
            return 'script';
        }

        return 'unknown';
    }
}
