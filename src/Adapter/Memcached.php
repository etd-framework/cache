<?php
/**
 * Part of the ETD Framework Cache Package
 *
 * @copyright   Copyright (C) 2016 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Cache\Adapter;

use Joomla\Cache\Adapter\Memcached as JoomlaMemcached;

class Memcached extends JoomlaMemcached {

    public function getKeys() {

        return $this->driver->getAllKeys();

    }

}
