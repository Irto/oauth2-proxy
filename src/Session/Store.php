<?php 
namespace Irto\OAuth2Proxy\Session;

use Illuminate\Session\Store as IlluminateStore;

class Store extends IlluminateStore {

    /**
     * {@inheritdoc}
     */
    public function start()
    {
        return $this->loadSession()->then(function () {
            if ( ! $this->has('_token')) $this->regenerateToken();

            return $this->started = true;
        });
    }

    /**
     * Load the session data from the handler.
     *
     * @return void
     */
    protected function loadSession()
    {
        return $this->readFromHandler()->then(function ($data) {
            $this->attributes = array_merge($this->attributes, $data);

            foreach (array_merge($this->bags, array($this->metaBag)) as $bag)
            {
                $this->initializeLocalBag($bag);

                $bag->initialize($this->bagData[$bag->getStorageKey()]);
            }
        });
    }

    /**
     * Read the session data from the handler.
     *
     * @return array
     */
    protected function readFromHandler()
    {
        return $this->handler->read($this->getId())->then(function ($data) {
            if ($data) {
                $data = @unserialize($this->prepareForUnserialize($data));

                if ($data !== false && $data !== null && is_array($data)) return $data;
            }

            return [];
        });

    }
}