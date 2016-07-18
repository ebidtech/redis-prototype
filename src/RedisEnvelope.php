<?php

/**
 * Redis envelope.
 *
 * This class represents a generic envelope for messages transported in Redis queues.
 *
 * Unauthorized copying or dissemination of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 *
 * @author     Diogo Teixeira <diogo.teixeira@emailbidding.com>
 * @copyright  Copyright (C) Wondeotec SA - All Rights Reserved
 * @license    LICENSE.txt
 */

namespace Prototype\Redis;

use Ramsey\Uuid\Uuid;

/**
 * Prototype\Redis\RedisEnvelope
 */
class RedisEnvelope implements \JsonSerializable
{
    const ID_KEY = 'id';
    const MESSAGE_KEY = 'message';

    /**
     * @var string
     */
    protected $id;

    /**
     * @var array
     */
    protected $message;

    /**
     * RedisEnvelope constructor.
     *
     * @param array       $message Message to enqueue.
     * @param string|null $id      Message identifier (if null an unique identifier is generated).
     */
    public function __construct(array $message, $id = null)
    {
        $this->id      = isset($id) ? $id : Uuid::uuid4();
        $this->message = $message;
    }

    /**
     * Creates a new instance from a JSON string.
     *
     * @param string $json JSON encoded envelope.
     *
     * @return static
     */
    public static function jsonDeserialize($json)
    {
        $decoded = json_decode($json, true);

        return new static($decoded[self::MESSAGE_KEY], $decoded[self::ID_KEY]);
    }

    /**
     * Get id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get message.
     *
     * @return array
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize()
    {
        return [
            self::ID_KEY      => $this->id,
            self::MESSAGE_KEY => $this->message,
        ];
    }
}
