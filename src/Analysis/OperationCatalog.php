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
        'set', 'setex', 'psetex', 'setnx', 'mset', 'msetnx', 'setrange', 'append', 'getset', 'getdel',
        'del', 'unlink', 'rename', 'renamenx', 'copy', 'move', 'restore', 'migrate',
        'incr', 'incrby', 'incrbyfloat', 'decr', 'decrby',
        'hset', 'hsetnx', 'hmset', 'hdel', 'hincrby', 'hincrbyfloat', 'hexpire', 'hpexpire', 'hexpireat',
        'hpexpireat', 'hpersist',
        'lpush', 'lpushx', 'rpush', 'rpushx', 'lpop', 'rpop', 'lset', 'linsert', 'lrem', 'ltrim', 'lmove',
        'blmove', 'rpoplpush', 'brpoplpush', 'lmpop', 'blmpop',
        'sadd', 'srem', 'smove', 'spop', 'sinterstore', 'sunionstore', 'sdiffstore',
        'zadd', 'zincrby', 'zrem', 'zremrangebyrank', 'zremrangebyscore', 'zremrangebylex', 'zpopmin',
        'zpopmax', 'bzpopmin', 'bzpopmax', 'zmpop', 'bzmpop', 'zinterstore', 'zunionstore', 'zdiffstore',
        'zrangestore',
        'expire', 'expireat', 'pexpire', 'pexpireat', 'persist',
        'flushdb', 'flushall', 'publish', 'spublish',
        'xadd', 'xdel', 'xtrim', 'xack', 'xclaim', 'xautoclaim', 'xgroup', 'xsetid',
        'pfadd', 'pfmerge', 'setbit', 'bitop', 'bitfield', 'geoadd', 'geosearchstore',
    ];

    public const REDIS_MUTATING_COMMANDS = [
        'SET', 'SETEX', 'PSETEX', 'SETNX', 'MSET', 'MSETNX', 'SETRANGE', 'APPEND', 'GETSET', 'GETDEL',
        'DEL', 'UNLINK', 'RENAME', 'RENAMENX', 'COPY', 'MOVE', 'RESTORE', 'MIGRATE',
        'INCR', 'INCRBY', 'INCRBYFLOAT', 'DECR', 'DECRBY',
        'HSET', 'HSETNX', 'HMSET', 'HDEL', 'HINCRBY', 'HINCRBYFLOAT', 'HEXPIRE', 'HPEXPIRE', 'HEXPIREAT',
        'HPEXPIREAT', 'HPERSIST',
        'LPUSH', 'LPUSHX', 'RPUSH', 'RPUSHX', 'LPOP', 'RPOP', 'LSET', 'LINSERT', 'LREM', 'LTRIM', 'LMOVE',
        'BLMOVE', 'RPOPLPUSH', 'BRPOPLPUSH', 'LMPOP', 'BLMPOP',
        'SADD', 'SREM', 'SMOVE', 'SPOP', 'SINTERSTORE', 'SUNIONSTORE', 'SDIFFSTORE',
        'ZADD', 'ZINCRBY', 'ZREM', 'ZREMRANGEBYRANK', 'ZREMRANGEBYSCORE', 'ZREMRANGEBYLEX', 'ZPOPMIN',
        'ZPOPMAX', 'BZPOPMIN', 'BZPOPMAX', 'ZMPOP', 'BZMPOP', 'ZINTERSTORE', 'ZUNIONSTORE', 'ZDIFFSTORE',
        'ZRANGESTORE',
        'EXPIRE', 'EXPIREAT', 'PEXPIRE', 'PEXPIREAT', 'PERSIST',
        'FLUSHDB', 'FLUSHALL', 'PUBLISH', 'SPUBLISH',
        'XADD', 'XDEL', 'XTRIM', 'XACK', 'XCLAIM', 'XAUTOCLAIM', 'XGROUP', 'XSETID',
        'PFADD', 'PFMERGE', 'SETBIT', 'BITOP', 'BITFIELD', 'GEOADD', 'GEOSEARCHSTORE',
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
