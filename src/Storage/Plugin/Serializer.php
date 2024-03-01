<?php

namespace Laminas\Cache\Storage\Plugin;

use Laminas\Cache\Storage\Capabilities;
use Laminas\Cache\Storage\Event;
use Laminas\Cache\Storage\PostEvent;
use Laminas\EventManager\EventManagerInterface;
use stdClass;

use function spl_object_hash;

class Serializer extends AbstractPlugin
{
    /** @var array */
    protected $capabilities = [];

    /**
     * {@inheritDoc}
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        // The higher the priority the sooner the plugin will be called on pre events
        // but the later it will be called on post events.
        $prePriority  = $priority;
        $postPriority = -$priority;

        // read
        $this->listeners[] = $events->attach('getItem.post', [$this, 'onReadItemPost'], $postPriority);
        $this->listeners[] = $events->attach('getItems.post', [$this, 'onReadItemsPost'], $postPriority);

        // write
        $this->listeners[] = $events->attach('setItem.pre', [$this, 'onWriteItemPre'], $prePriority);
        $this->listeners[] = $events->attach('setItems.pre', [$this, 'onWriteItemsPre'], $prePriority);

        $this->listeners[] = $events->attach('addItem.pre', [$this, 'onWriteItemPre'], $prePriority);
        $this->listeners[] = $events->attach('addItems.pre', [$this, 'onWriteItemsPre'], $prePriority);

        $this->listeners[] = $events->attach('replaceItem.pre', [$this, 'onWriteItemPre'], $prePriority);
        $this->listeners[] = $events->attach('replaceItems.pre', [$this, 'onWriteItemsPre'], $prePriority);

        $this->listeners[] = $events->attach('checkAndSetItem.pre', [$this, 'onWriteItemPre'], $prePriority);

        // overwrite capabilities
        $this->listeners[] = $events->attach('getCapabilities.post', [$this, 'onGetCapabilitiesPost'], $postPriority);
    }

    /**
     * On read item post
     *
     * @return void
     */
    public function onReadItemPost(PostEvent $event)
    {
        $result = $event->getResult();
        if ($result !== null) {
            $serializer = $this->getOptions()->getSerializer();
            $result     = $serializer->unserialize($result);
            $event->setResult($result);
        }
    }

    /**
     * On read items post
     *
     * @return void
     */
    public function onReadItemsPost(PostEvent $event)
    {
        $serializer = $this->getOptions()->getSerializer();
        $result     = $event->getResult();
        foreach ($result as &$value) {
            $value = $serializer->unserialize($value);
        }
        $event->setResult($result);
    }

    /**
     * On write item pre
     *
     * @return void
     */
    public function onWriteItemPre(Event $event)
    {
        $serializer      = $this->getOptions()->getSerializer();
        $params          = $event->getParams();
        $params['value'] = $serializer->serialize($params['value']);
        /** Passed by {@see AbstractAdapter::checkAndSetItem()}. Used to compare with the already cached value. */
        if (isset($params['token'])) {
            $params['token'] = $serializer->serialize($params['token']);
        }
    }

    /**
     * On write items pre
     *
     * @return void
     */
    public function onWriteItemsPre(Event $event)
    {
        $serializer = $this->getOptions()->getSerializer();
        $params     = $event->getParams();
        foreach ($params['keyValuePairs'] as &$value) {
            $value = $serializer->serialize($value);
        }
    }

    /**
     * On get capabilities
     *
     * @return void
     */
    public function onGetCapabilitiesPost(PostEvent $event)
    {
        $baseCapabilities = $event->getResult();
        $index            = spl_object_hash($baseCapabilities);

        if (! isset($this->capabilities[$index])) {
            $this->capabilities[$index] = new Capabilities(
                $baseCapabilities->getAdapter(),
                new stdClass(), // marker
                [
                    'supportedDatatypes' => [
                        'NULL'     => true,
                        'boolean'  => true,
                        'integer'  => true,
                        'double'   => true,
                        'string'   => true,
                        'array'    => true,
                        'object'   => 'object',
                        'resource' => false,
                    ],
                ],
                $baseCapabilities
            );
        }

        $event->setResult($this->capabilities[$index]);
    }
}
