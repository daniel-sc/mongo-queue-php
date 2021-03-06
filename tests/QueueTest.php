<?php

namespace DominionEnterprises\Mongo;

/**
 * @coversDefaultClass \DominionEnterprises\Mongo\Queue
 * @covers ::<private>
 */
final class QueueTest extends \PHPUnit_Framework_TestCase
{
    private $collection;
    private $mongoUrl;
    /** @var  Queue */
    private $queue;

    public function setUp()
    {
        $this->mongoUrl = getenv('TESTING_MONGO_URL') ?: 'mongodb://localhost:27017';
        $mongo = new \MongoClient($this->mongoUrl);
        $this->collection = $mongo->selectDB('testing')->selectCollection('messages');
        $this->collection->drop();

        $this->queue = new Queue($this->mongoUrl, 'testing', 'messages');
    }

    /**
     * @test
     * @covers ::__construct
     * @expectedException \InvalidArgumentException
     */
    public function constructWithNonStringUrl()
    {
        new Queue(1, 'testing', 'messages');
    }

    /**
     * @test
     * @covers ::__construct
     * @expectedException \InvalidArgumentException
     */
    public function constructWithNonStringDb()
    {
        new Queue($this->mongoUrl, true, 'messages');
    }

    /**
     * @test
     * @covers ::__construct
     * @expectedException \InvalidArgumentException
     */
    public function constructWithNonStringCollection()
    {
        new Queue($this->mongoUrl, 'testing', new \stdClass());
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     */
    public function ensureGetIndex()
    {
        $this->queue->ensureGetIndex(['type' => 1], ['boo' => -1]);
        $this->queue->ensureGetIndex(['another.sub' => 1]);

        $this->assertSame(4, count($this->collection->getIndexInfo()));

        $expectedOne = [
            'running' => 1,
            'payload.type' => 1,
            'priority' => 1,
            'created' => 1,
            'payload.boo' => -1,
            'earliestGet' => 1
        ];
        $resultOne = $this->collection->getIndexInfo();
        $this->assertSame($expectedOne, $resultOne[1]['key']);

        $expectedTwo = ['running' => 1, 'resetTimestamp' => 1];
        $resultTwo = $this->collection->getIndexInfo();
        $this->assertSame($expectedTwo, $resultTwo[2]['key']);

        $expectedThree = [
            'running' => 1,
            'payload.another.sub' => 1,
            'priority' => 1,
            'created' => 1,
            'earliestGet' => 1
        ];
        $resultThree = $this->collection->getIndexInfo();
        $this->assertSame($expectedThree, $resultThree[3]['key']);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \Exception
     */
    public function ensureGetIndexWithTooLongCollectionName()
    {
        $collectionName = 'messages012345678901234567890123456789012345678901234567890123456789';
        $collectionName .= '012345678901234567890123456789012345678901234567890123456789';//128 chars

        $queue = new Queue($this->mongoUrl, 'testing', $collectionName);
        $queue->ensureGetIndex([]);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithNonStringBeforeSortKey()
    {
        $this->queue->ensureGetIndex([0 => 1]);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithNonStringAfterSortKey()
    {
        $this->queue->ensureGetIndex(['field' => 1], [0 => 1]);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithBadBeforeSortValue()
    {
        $this->queue->ensureGetIndex(['field' => 'NotAnInt']);
    }

    /**
     * @test
     * @covers ::ensureGetIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureGetIndexWithBadAfterSortValue()
    {
        $this->queue->ensureGetIndex([], ['field' => 'NotAnInt']);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     */
    public function ensureCountIndex()
    {
        $this->queue->ensureCountIndex(['type' => 1, 'boo' => -1], false);
        $this->queue->ensureCountIndex(['another.sub' => 1], true);

        $this->assertSame(3, count($this->collection->getIndexInfo()));

        $expectedOne = ['payload.type' => 1, 'payload.boo' => -1];
        $resultOne = $this->collection->getIndexInfo();
        $this->assertSame($expectedOne, $resultOne[1]['key']);

        $expectedTwo = ['running' => 1, 'payload.another.sub' => 1];
        $resultTwo = $this->collection->getIndexInfo();
        $this->assertSame($expectedTwo, $resultTwo[2]['key']);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     */
    public function ensureCountIndexWithPrefixOfPrevious()
    {
        $this->queue->ensureCountIndex(['type' => 1, 'boo' => -1], false);
        $this->queue->ensureCountIndex(['type' => 1], false);

        $this->assertSame(2, count($this->collection->getIndexInfo()));

        $expected = ['payload.type' => 1, 'payload.boo' => -1];
        $result = $this->collection->getIndexInfo();
        $this->assertSame($expected, $result[1]['key']);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureCountIndexWithNonStringKey()
    {
        $this->queue->ensureCountIndex([0 => 1], false);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureCountIndexWithBadValue()
    {
        $this->queue->ensureCountIndex(['field' => 'NotAnInt'], false);
    }

    /**
     * @test
     * @covers ::ensureCountIndex
     * @expectedException \InvalidArgumentException
     */
    public function ensureCountIndexWithNonBoolIncludeRunning()
    {
        $this->queue->ensureCountIndex(['field' => 1], 1);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getByBadQuery()
    {
        $this->queue->send(['key1' => 0, 'key2' => true]);

        $result = $this->queue->get(['key3' => 0], PHP_INT_MAX, 0);
        $this->assertNull($result);

        $this->assertSame(1, $this->collection->count());
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonIntWaitDuration()
    {
        $this->queue->get([], 0, 'NotAnInt');
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonIntPollDuration()
    {
        $this->queue->get([], 0, 0, new \stdClass());
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getWithNegativePollDuration()
    {
        $this->queue->send(['key1' => 0]);
        $this->assertNotNull($this->queue->get([], 0, 0, -1));
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonStringKey()
    {
        $this->queue->get([0 => 'a value'], 0);
    }

    /**
     * @test
     * @covers ::get
     * @expectedException \InvalidArgumentException
     */
    public function getWithNonIntRunningResetDuration()
    {
        $this->queue->get([], true);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getByFullQuery()
    {
        $messageOne = ['id' => 'SHOULD BE REMOVED', 'key1' => 0, 'key2' => true];

        $this->queue->send($messageOne);
        $this->queue->send(['key' => 'value']);

        $result = $this->queue->get($messageOne, PHP_INT_MAX, 0);

        $this->assertNotSame($messageOne['id'], $result['id']);

        $messageOne['id'] = $result['id'];
        $this->assertSame($messageOne, $result);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getBySubDocQuery()
    {
        $messageTwo = [
            'one' => [
                'two' => [
                    'three' => 5,
                    'notused' => 'notused',
                ],
                'notused' => 'notused',
            ],
            'notused' => 'notused',
        ];

        $this->queue->send(['key1' => 0, 'key2' => true]);
        $this->queue->send($messageTwo);

        $result = $this->queue->get(['one.two.three' => ['$gt' => 4]], PHP_INT_MAX, 0);
        $this->assertSame(['id' => $result['id']] + $messageTwo, $result);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getBeforeAck()
    {
        $messageOne = ['key1' => 0, 'key2' => true];

        $this->queue->send($messageOne);
        $this->queue->send(['key' => 'value']);

        $this->queue->get($messageOne, PHP_INT_MAX, 0);

        //try get message we already have before ack
        $result = $this->queue->get($messageOne, PHP_INT_MAX, 0);
        $this->assertNull($result);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getWithCustomPriority()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];
        $messageThree = ['key' => 2];

        $this->queue->send($messageOne, 0, 0.5);
        $this->queue->send($messageTwo, 0, 0.4);
        $this->queue->send($messageThree, 0, 0.3);

        $resultOne = $this->queue->get([], PHP_INT_MAX, 0);
        $resultTwo = $this->queue->get([], PHP_INT_MAX, 0);
        $resultThree = $this->queue->get([], PHP_INT_MAX, 0);

        $this->assertSame(['id' => $resultOne['id']] + $messageThree, $resultOne);
        $this->assertSame(['id' => $resultTwo['id']] + $messageTwo, $resultTwo);
        $this->assertSame(['id' => $resultThree['id']] + $messageOne, $resultThree);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function getWithTimeBasedPriority()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];
        $messageThree = ['key' => 2];

        $this->queue->send($messageOne);
        $this->queue->send($messageTwo);
        $this->queue->send($messageThree);

        $resultOne = $this->queue->get([], PHP_INT_MAX, 0);
        $resultTwo = $this->queue->get([], PHP_INT_MAX, 0);
        $resultThree = $this->queue->get([], PHP_INT_MAX, 0);

        $this->assertSame(['id' => $resultOne['id']] + $messageOne, $resultOne);
        $this->assertSame(['id' => $resultTwo['id']] + $messageTwo, $resultTwo);
        $this->assertSame(['id' => $resultThree['id']] + $messageThree, $resultThree);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     * @uses \DominionEnterprises\Mongo\Queue::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::requeue
     */
    public function getWithTimeBasedPriorityWithOldTimestamp()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];
        $messageThree = ['key' => 2];

        $this->queue->send($messageOne);
        $this->queue->send($messageTwo);
        $this->queue->send($messageThree);

        $resultTwo = $this->queue->get([], PHP_INT_MAX, 0);
        //ensuring using old timestamp shouldn't affect normal time order of send()s
        $this->queue->requeue($resultTwo, 0, 0.0, false);

        $resultOne = $this->queue->get([], PHP_INT_MAX, 0);
        $resultTwo = $this->queue->get([], PHP_INT_MAX, 0);
        $resultThree = $this->queue->get([], PHP_INT_MAX, 0);

        $this->assertSame(['id' => $resultOne['id']] + $messageOne, $resultOne);
        $this->assertSame(['id' => $resultTwo['id']] + $messageTwo, $resultTwo);
        $this->assertSame(['id' => $resultThree['id']] + $messageThree, $resultThree);
    }

    /**
     * @test
     * @covers ::get
     */
    public function getWait()
    {
        $start = microtime(true);

        $this->queue->get([], PHP_INT_MAX, 200);

        $end = microtime(true);

        $this->assertTrue($end - $start >= 0.200);
        $this->assertTrue($end - $start < 0.300);
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function earliestGet()
    {
         $messageOne = ['key1' => 0, 'key2' => true];

         $this->queue->send($messageOne, time() + 1);

         $this->assertNull($this->queue->get($messageOne, PHP_INT_MAX, 0));

         sleep(1);

         $this->assertNotNull($this->queue->get($messageOne, PHP_INT_MAX, 0));
    }

    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function resetStuck()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];

        $this->queue->send($messageOne);
        $this->queue->send($messageTwo);

        //sets to running
        $this->collection->update(
            ['payload.key' => 0],
            ['$set' => ['running' => true, 'resetTimestamp' => new \MongoDate()]]
        );
        $this->collection->update(
            ['payload.key' => 1],
            ['$set' => ['running' => true, 'resetTimestamp' => new \MongoDate()]]
        );

        $this->assertSame(2, $this->collection->count(['running' => true]));

        //sets resetTimestamp on messageOne
        $this->queue->get($messageOne, 0, 0);

        //resets and gets messageOne
        $this->assertNotNull($this->queue->get($messageOne, PHP_INT_MAX, 0));

        $this->assertSame(1, $this->collection->count(['running' => false]));
    }
    
    /**
     * @test
     * @covers ::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function resetStuckWithResetCallback()
    {
        $messageOne = ['key' => 0];
        $messageTwo = ['key' => 1];

        $this->queue->send($messageOne);
        $this->queue->send($messageTwo);
    
        //sets to running
        $this->collection->update(
            ['payload.key' => 0],
            ['$set' => ['running' => true, 'resetTimestamp' => new \MongoDate()]]
        );
        $this->collection->update(
            ['payload.key' => 1],
            ['$set' => ['running' => true, 'resetTimestamp' => new \MongoDate()]]
        );
    
        $this->assertSame(2, $this->collection->count(['running' => true]));
    
        //sets resetTimestamp on messageOne
        $messageOneGet = $this->queue->get($messageOne, 0, 0);
    
        $countResetCallbackInvokes = 0;
        $resetCallback = function ($msg) use (&$countResetCallbackInvokes, $messageOneGet) {
            $countResetCallbackInvokes++;
            $this->assertEquals($messageOneGet['id']->{'$id'}, $msg['_id']->{'$id'});
        };
        //resets and gets messageOne
        $this->assertNotNull($this->queue->get($messageOne, PHP_INT_MAX, 0, 200, $resetCallback));
    
        $this->assertSame(1, $this->collection->count(['running' => false]));
        $this->assertSame(1, $countResetCallbackInvokes);
    }

    /**
     * @test
     * @covers ::count
     * @expectedException \InvalidArgumentException
     */
    public function countWithNonNullOrBoolRunning()
    {
        $this->queue->count([], 1);
    }

    /**
     * @test
     * @covers ::count
     * @expectedException \InvalidArgumentException
     */
    public function countWithNonStringKey()
    {
        $this->queue->count([0 => 'a value']);
    }

    /**
     * @test
     * @covers ::count
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function testCount()
    {
        $message = ['boo' => 'scary'];

        $this->assertSame(0, $this->queue->count($message, true));
        $this->assertSame(0, $this->queue->count($message, false));
        $this->assertSame(0, $this->queue->count($message));

        $this->queue->send($message);
        $this->assertSame(1, $this->queue->count($message, false));
        $this->assertSame(0, $this->queue->count($message, true));
        $this->assertSame(1, $this->queue->count($message));

        $this->queue->get($message, PHP_INT_MAX, 0);
        $this->assertSame(0, $this->queue->count($message, false));
        $this->assertSame(1, $this->queue->count($message, true));
        $this->assertSame(1, $this->queue->count($message));
    }

    /**
     * @test
     * @covers ::updateResetDuration
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function updateResetDuration()
    {
        $messageOne = ['key' => 'value'];
        $this->queue->send($messageOne);
        $message = $this->queue->get($messageOne, 1000, 0);

        $newResetDuration = 100;
        $this->queue->updateResetDuration($message, $newResetDuration);

        $queueElement = $this->collection->findOne();
        $this->assertLessThanOrEqual(time() + $newResetDuration, $queueElement['resetTimestamp']->sec);
        $this->assertGreaterThan(time() + $newResetDuration - 10, $queueElement['resetTimestamp']->sec);
    }
    
    /**
     * @test
     * @covers ::updateResetDuration
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function updateResetDurationWithHightResetDuration()
    {
        $messageOne = ['key' => 'value'];
        $this->queue->send($messageOne);
        $message = $this->queue->get($messageOne, 1000, 0);
        
        $newResetDuration = PHP_INT_MAX;
        $this->queue->updateResetDuration($message, $newResetDuration);
        
        $queueElement = $this->collection->findOne();
        $this->assertEquals(Queue::MONGO_INT32_MAX, $queueElement['resetTimestamp']->sec);
    }
    
    /**
     * @test
     * @covers ::updateResetDuration
     * @expectedException \InvalidArgumentException
     */
    public function updateResetDurationWithoutMongoId()
    {
        $this->queue->updateResetDuration([], 1);
    }
    
    /**
     * @test
     * @covers ::updateResetDuration
     * @expectedException \InvalidArgumentException
     */
    public function updateResetDurationWithInvalidDuration()
    {
        $this->queue->updateResetDuration(['id' => new \MongoId()], 1.1);
    }

    /**
     * @test
     * @covers ::ack
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ack()
    {
        $messageOne = ['key1' => 0, 'key2' => true];

        $this->queue->send($messageOne);
        $this->queue->send(['key' => 'value']);

        $result = $this->queue->get($messageOne, PHP_INT_MAX, 0);
        $this->assertSame(2, $this->collection->count());

        $this->queue->ack($result);
        $this->assertSame(1, $this->collection->count());
    }

    /**
     * @test
     * @covers ::ack
     * @expectedException \InvalidArgumentException
     */
    public function ackBadArg()
    {
        $this->queue->ack(['id' => new \stdClass()]);
    }

    /**
     * @test
     * @covers ::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ackSend()
    {
        $messageOne = ['key1' => 0, 'key2' => true];
        $messageThree = ['hi' => 'there', 'rawr' => 2];

        $this->queue->send($messageOne);
        $this->queue->send(['key' => 'value']);

        $resultOne = $this->queue->get($messageOne, PHP_INT_MAX, 0);
        $this->assertSame(2, $this->collection->count());

        $this->queue->ackSend($resultOne, $messageThree);
        $this->assertSame(2, $this->collection->count());

        $actual = $this->queue->get(['hi' => 'there'], PHP_INT_MAX, 0);
        $expected = ['id' => $resultOne['id']] + $messageThree;

        $actual['id'] = $actual['id']->__toString();
        $expected['id'] = $expected['id']->__toString();
        $this->assertSame($expected, $actual);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithWrongIdType()
    {
        $this->queue->ackSend(['id' => 5], []);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNanPriority()
    {
        $this->queue->ackSend(['id' => new \MongoId()], [], 0, NAN);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNonFloatPriority()
    {
        $this->queue->ackSend(['id' => new \MongoId()], [], 0, 'NotAFloat');
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNonIntEarliestGet()
    {
        $this->queue->ackSend(['id' => new \MongoId()], [], true);
    }

    /**
     * @test
     * @covers ::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function ackSendWithNonBoolNewTimestamp()
    {
        $this->queue->ackSend(['id' => new \MongoId()], [], 0, 0.0, 1);
    }

    /**
     * @test
     * @covers ::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ackSendWithHighEarliestGet()
    {
        $this->queue->send([]);
        $messageToAck = $this->queue->get([], PHP_INT_MAX, 0);

        $this->queue->ackSend($messageToAck, [], PHP_INT_MAX);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => Queue::MONGO_INT32_MAX,
            'priority' => 0.0,
        ];

        $message = $this->collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function ackSendWithLowEarliestGet()
    {
        $this->queue->send([]);
        $messageToAck = $this->queue->get([], PHP_INT_MAX, 0);

        $this->queue->ackSend($messageToAck, [], -1);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => 0,
            'priority' => 0.0,
        ];

        $message = $this->collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::requeue
     * @uses \DominionEnterprises\Mongo\Queue::get
     * @uses \DominionEnterprises\Mongo\Queue::ackSend
     * @uses \DominionEnterprises\Mongo\Queue::send
     */
    public function requeue()
    {
        $messageOne = ['key1' => 0, 'key2' => true];

        $this->queue->send($messageOne);
        $this->queue->send(['key' => 'value']);

        $resultBeforeRequeue = $this->queue->get($messageOne, PHP_INT_MAX, 0);

        $this->queue->requeue($resultBeforeRequeue);
        $this->assertSame(2, $this->collection->count());

        $resultAfterRequeue = $this->queue->get($messageOne, 0);
        $this->assertSame(['id' => $resultAfterRequeue['id']] + $messageOne, $resultAfterRequeue);
    }

    /**
     * @test
     * @covers ::requeue
     * @uses \DominionEnterprises\Mongo\Queue::ackSend
     * @expectedException \InvalidArgumentException
     */
    public function requeueBadArg()
    {
        $this->queue->requeue(['id' => new \stdClass()]);
    }

    /**
     * @test
     * @covers ::send
     */
    public function send()
    {
        $payload = ['key1' => 0, 'key2' => true];
        $this->queue->send($payload, 34, 0.8);

        $expected = [
            'payload' => $payload,
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => 34,
            'priority' => 0.8,
        ];

        $message = $this->collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::send
     * @expectedException \InvalidArgumentException
     */
    public function sendWithNanPriority()
    {
        $this->queue->send([], 0, NAN);
    }

    /**
     * @test
     * @covers ::send
     * @expectedException \InvalidArgumentException
     */
    public function sendWithNonIntegerEarliestGet()
    {
        $this->queue->send([], true);
    }

    /**
     * @test
     * @covers ::send
     * @expectedException \InvalidArgumentException
     */
    public function sendWithNonFloatPriority()
    {
        $this->queue->send([], 0, new \stdClass());
    }

    /**
     * @test
     * @covers ::send
     */
    public function sendWithHighEarliestGet()
    {
        $this->queue->send([], PHP_INT_MAX);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => Queue::MONGO_INT32_MAX,
            'priority' => 0.0,
        ];

        $message = $this->collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * @test
     * @covers ::send
     */
    public function sendWithLowEarliestGet()
    {
        $this->queue->send([], -1);

        $expected = [
            'payload' => [],
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => 0,
            'priority' => 0.0,
        ];

        $message = $this->collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }

    /**
     * Verify Queue can be constructed with \MongoCollection
     *
     * @test
     * @covers ::__construct
     *
     * @return void
     */
    public function constructWithCollection()
    {
        $mongo = new \MongoClient($this->mongoUrl);
        $collection = $mongo->selectDB('testing')->selectCollection('custom_collection');
        $collection->drop();
        $queue = new Queue($collection);

        $payload = ['key1' => 0, 'key2' => true];
        $queue->send($payload, 34, 0.8);

        $expected = [
            'payload' => $payload,
            'running' => false,
            'resetTimestamp' => Queue::MONGO_INT32_MAX,
            'earliestGet' => 34,
            'priority' => 0.8,
        ];

        $this->assertSame(1, $collection->count());

        $message = $collection->findOne();

        $this->assertLessThanOrEqual(time(), $message['created']->sec);
        $this->assertGreaterThan(time() - 10, $message['created']->sec);

        unset($message['_id'], $message['created']);
        $message['resetTimestamp'] = $message['resetTimestamp']->sec;
        $message['earliestGet'] = $message['earliestGet']->sec;

        $this->assertSame($expected, $message);
    }
}
