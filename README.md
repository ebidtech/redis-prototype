# Redis Queue Prototype

This prototype is meant as a proof of concept for some more advanced features a queues implemented in
Redis. The features here tested are single command multi consume, delayed consume and message
acknowledgement.

## Disclaimer

This is a simple prototype for Redis queues, created as a proof of concept. The code is not supported,
and probably not suitable for real world usage. Use at your own risk.

## Multi Consume

The explored multi consume solution relies on the execution of Lua code server (Redis) side. More common
approaches include multiple calls to `lpop`, either by themselves or as part of a pipeline.

This approach relies on both `lrange` and `ltrim` commands. `lrange` is initially used to retrieve the
required number of messages from the queue. If the number of messages in the queue is less than requested,
all messages of that queue are retrieved. Then, the `ltrim` command is used to remove the consumed messages.

The advantages of this approach are the following:
* single round trip;
* reduction of client side logic and error handling;
* reduced number of Redis instructions (two independently of how many messages are consumed);
* operation is atomic (script execution is always atomic in Redis);

### Benchmark

This implementation was benchmarked against the other two more common solutions (multiple regular and 
pipelined `lpop`).

Each benchmarks started with the same of number of messages. The objective of each benchmark was to retrieve
the specified number of messages, and returning them in an array, after deserialized into multiple envelope
instances.

Please note that due to the nature of other features in this prototype, the proposed multi consume solution
executes additional logic that the other two approaches do not. This was kept during the benchmark. However,
as there are no messages delayed or awaiting acknowledgement in the benchmark data set, the additional work
is expected to have no or little impact in performance.

#### Parameters

Benchmarks were performed using PHPBench (https://github.com/phpbench/phpbench).

Every benchmark iteration started with a queue with 1000 messages. Messages where removed in increments of 50,
until the queue was empty (20 revolutions). The test was comprised of a total of 10 iterations, resulting in
a total of 10000 messages consumed.

#### Results

```
\Prototype\Redis\Benchmark\RedisQueueSimpleBench

    benchLpopConsecutive          I9 P0         [μ Mo]/r: 3.235 3.114 (ms)      [μSD μRSD]/r: 0.382ms 11.80%
    benchLpopInternal             I9 P0         [μ Mo]/r: 0.860 0.811 (ms)      [μSD μRSD]/r: 0.119ms 13.82%
    benchLpopPipeline             I9 P0         [μ Mo]/r: 1.608 1.538 (ms)      [μSD μRSD]/r: 0.131ms 8.16%

3 subjects, 30 iterations, 60 revs, 0 rejects
(best [mean mode] worst) = 0.725 [1.901 1.821] 1.129 (ms)
⅀T: 57.033ms μSD/r 0.211ms μRSD/r: 11.259%
suite: 133a0ce205f614387085e99067dc76320d1a0af2, date: 2016-07-18, stime: 09:59:12
+-----------------------+----------------------+--------+--------+------+------+-----+------------+---------+---------+---------+
| benchmark             | subject              | groups | params | revs | iter | rej | mem        | time    | z-value | diff    |
+-----------------------+----------------------+--------+--------+------+------+-----+------------+---------+---------+---------+
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 0    | 0   | 5,413,560b | 2.928ms | -0.81σ  | +75.22% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 1    | 0   | 5,413,560b | 3.191ms | -0.12σ  | +77.26% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 2    | 0   | 5,413,560b | 3.346ms | +0.29σ  | +78.32% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 3    | 0   | 5,413,560b | 3.199ms | -0.09σ  | +77.32% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 4    | 0   | 5,413,560b | 3.057ms | -0.47σ  | +76.27% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 5    | 0   | 5,413,560b | 3.005ms | -0.6σ   | +75.86% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 6    | 0   | 5,413,560b | 4.251ms | +2.66σ  | +82.93% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 7    | 0   | 5,413,560b | 2.816ms | -1.1σ   | +74.24% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 8    | 0   | 5,413,560b | 3.117ms | -0.31σ  | +76.73% |
| RedisQueueSimpleBench | benchLpopConsecutive |        | []     | 20   | 9    | 0   | 5,413,560b | 3.444ms | +0.55σ  | +78.93% |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 0    | 0   | 5,413,560b | 0.806ms | -0.45σ  | +10.00% |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 1    | 0   | 5,413,560b | 0.905ms | +0.38σ  | +19.81% |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 2    | 0   | 5,413,560b | 0.725ms | -1.13σ  | 0.00%   |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 3    | 0   | 5,413,560b | 0.789ms | -0.59σ  | +8.09%  |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 4    | 0   | 5,413,560b | 0.881ms | +0.18σ  | +17.64% |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 5    | 0   | 5,413,560b | 1.129ms | +2.27σ  | +35.72% |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 6    | 0   | 5,413,560b | 0.997ms | +1.16σ  | +27.22% |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 7    | 0   | 5,413,560b | 0.803ms | -0.48σ  | +9.61%  |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 8    | 0   | 5,413,560b | 0.727ms | -1.12σ  | +0.14%  |
| RedisQueueSimpleBench | benchLpopInternal    |        | []     | 20   | 9    | 0   | 5,413,560b | 0.835ms | -0.21σ  | +13.14% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 0    | 0   | 5,413,560b | 1.698ms | +0.68σ  | +57.27% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 1    | 0   | 5,413,560b | 1.545ms | -0.48σ  | +53.05% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 2    | 0   | 5,413,560b | 1.474ms | -1.03σ  | +50.77% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 3    | 0   | 5,413,560b | 1.742ms | +1.02σ  | +58.35% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 4    | 0   | 5,413,560b | 1.554ms | -0.41σ  | +53.33% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 5    | 0   | 5,413,560b | 1.900ms | +2.22σ  | +61.82% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 6    | 0   | 5,413,560b | 1.460ms | -1.13σ  | +50.31% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 7    | 0   | 5,413,560b | 1.648ms | +0.30σ  | +55.98% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 8    | 0   | 5,413,560b | 1.548ms | -0.46σ  | +53.13% |
| RedisQueueSimpleBench | benchLpopPipeline    |        | []     | 20   | 9    | 0   | 5,413,560b | 1.514ms | -0.72σ  | +52.09% |
+-----------------------+----------------------+--------+--------+------+------+-----+------------+---------+---------+---------+

```

#### Analysis

Despite the additional work, the single command approach was by far more efficient than the other two options.
It also suffered some significant fluctuations in some iterations, offsetting as much as 35% worst performance than
the best run.

Pipeline tests were better than multiple single instructions, as expected, but still ranked at least 50% worst than
the best single instruction run. Multiple single instructions were at least 75% worse than the best single instruction
run.

## Delayed Consume

Delayed consume works by creating a "delayed shadow queue" for the original queue. This queue is implemented using
an ordered set instead of a list, like regular queues. This set used delay expiration times as score, in
the form of unix timestamps.

Before consuming items from a queue, this set is queried in order to obtain all messages whose score (timestamp)
is lower than the current timestamp. Any message in this state is automatically added to the end of the original
queue and removed from the set.

This prototype executes this logic on every consume operation. If performance if a concern, the concept can easily
be adapted to be performed within a daemon.

### Limitations

Technically there is a maximum score limit that is supported by Redis, which would represent a maximum delay
for messages, as scores represent unix timestamps. However, Redis ordered sets represent scores as IEEE 754 
floating point number, which means that timestamps up to 2^53 can be safely used. Such a timestamp is hundreds
of millions of years into the future.

Performance can also be a limitation, if the delayed queue transactions are performed before every consume. As
explained above, the problem can be mitigated/eliminated by daemonizing the transactions.

## Message acknowledgement

Message acknowledgement works very similarly to delayed messages. When consuming a message, if the acknowledge flag
is set, the message is automatically published to an "acknowledge shadow queue" of the original queue. This queue
is also represented using an ordered set, but in this case the score represents the maximum time we should wait
for the message to be acknowledged before publishing it again.

There's also another "shadow queue" used as message storage. While the ordered sets contains only message identifiers
and their respective scores, this new queue, represents by an hash, maps the message identifier to the message itself.

As with delayed messages, this prototype will check for any expired acknowledge messages before every consume, 
and publish those again in the original queue. As before, the process can be daemonized.

If a messages is acknowledged, it is removed from the shadow queue and is never published again.

### Limitations

The first limitation is related to message identity. In order to acknowledge a message, removing it from the ordered
set, it must be found. To achieve this, the message should contain an unique identifier at its top level, under the
key `id`. An optimization could be made to this by using the ordered set directly as message storage, removing the
need for a second "shadow queue". However, this approach has a somewhat fragile subtlety: message identity match would
be made on the entire message body, so a full, unaltered copy of the message would be needed in order to acknowledge it.

The message identity problem introduces yet another limitation: messages must be encoded as JSON, and must contain a top
level key named `id`. As this processing is done on Redis' side, the options here are quite limited. You can, however,
represent the message data as you please, including compressed/encrypted/encoded.

The second limitation is related to queue sharding. In sharded environments, you must always ensure a consistent
key distribution in order for this to work. Supposed for example a round robin sharded system, where each queue
is sharded in multiple Redis nodes. In order to acknowledge a message you must ensure that the acknowledgement
request is always sent to the same node where the message was originally consumed. If this is not enforced you
risk sending acknowledgement requests to a node that is not expecting that acknowledgment, which will result in
the message being published again in the original node (as technically it was never acknowledged).
