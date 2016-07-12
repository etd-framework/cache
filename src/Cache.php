<?php
/**
 * Part of the ETD Framework Cache Package
 *
 * @copyright   Copyright (C) 2015-2016 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Cache;

use Joomla\Cache\Item\Item;
use Joomla\DI\Container;
use Joomla\DI\ContainerAwareInterface;
use Joomla\DI\ContainerAwareTrait;

class Cache implements ContainerAwareInterface {

    /**
     * @var string Contexte de cache (permet de gérer plusieurs applications dans le même cache)
     */
    protected $context = '__default';

    /**
     * @var string Nom brut.
     */
    protected $rawname;

    /**
     * @var \Joomla\Cache\AbstractCacheItemPool
     */
    protected $adapter;

    /**
     * @var string Groupe par défaut de stockage des données.
     */
    protected $default_group = 'default';

    /**
     * @var int Durée de vie en secondes des données mises en cache.
     */
    protected $ttl = 900;

    /**
     * @var array Un tableau d'options.
     */
    protected $options = [];

    use ContainerAwareTrait;

    public function __construct(Container $container) {

        // On définit le container DI.
        $this->setContainer($container);

        // On instancie l'adaptateur de cache.
        $config       = $container->get('config');
        $adapter_name = strtolower($config->get('cache.adapter'));

        // On extrait les options de la configuration.
        $options = $config->extract('cache.options');
        if (isset($options)) {
            $this->options = $options->toArray();
        } else {
            $this->options = [];
        }

        // On prépare les arguments passés au constructeur de l'adaptateur.
        $args = [$this->options];

        // On choisit le bon adaptateur en fonction de la configuration.
        switch ($adapter_name) {
            case 'apc':
                $class = "\\Joomla\\Cache\\Adapter\\Apc";
                break;
            case 'file':
                $class = "\\Joomla\\Cache\\Adapter\\File";
                break;
            case 'memcached':
                $class = "\\Joomla\\Cache\\Adapter\\Memcached";
                break;
            case 'memcachesasl':
                $class = "\\EtdSolutions\\Cache\\Adapter\\MemcacheSASL";
                break;
            case 'redis':
                $class = "\\Joomla\\Cache\\Adapter\\Redis";
                break;
            case 'runtime':
                $class = "\\Joomla\\Cache\\Adapter\\Runtime";
                break;
            case 'wincache':
                $class = "\\Joomla\\Cache\\Adapter\\Wincache";
                break;
            case 'xcache':
                $class = "\\Joomla\\Cache\\Adapter\\XCache";
                break;
            case 'none':
            default:
                $class = "\\Joomla\\Cache\\Adapter\\None";
                break;
        }

        // On contrôle que la classe existe.
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf('%s cache adapter class does not exist', $class));
        }

        // On contrôle que la classe est supportée par le système.
        if (!$class::isSupported()) {
            throw new \InvalidArgumentException(sprintf('%s cache adapter is not supported', $class));
        }

        // On instancie les drivers si besoin.
        if ($adapter_name == "memcached") {
            $mc = new \Memcached($this->options['persistent_id']);
            $mc->setOption(\Memcached::OPT_BINARY_PROTOCOL, false);
            if (!count($mc->getServerList()) && isset($this->options['servers']) && is_array($this->options['servers'])) {
                $mc->addServers($this->options['servers']);
            }
            array_unshift($args, $mc);
        }

        // On instancie
        $this->adapter = new $class(...$args);

        // Durée de vie (TTL)
        if (isset($this->options['ttl'])) {
            $this->ttl = (int)$this->options['ttl'];
        }

    }

    /**
     * Donne le contexte de mise en cache.
     *
     * @return string Le contexte.
     */
    public function getContext() {

        return $this->context;
    }

    /**
     * Définit le contexte de mise en cache.
     *
     * @param string $context Le contexte.
     *
     * @return Cache Pour le chainage.
     */
    public function setContext($context) {

        $this->context = $context;

        return $this;
    }

    /**
     * Récupère les données mises en cache par un identifiant et un groupe.
     *
     * @param   string $id    L'identifiant
     * @param   string $group Le groupe
     *
     * @return  mixed  boolean  False on failure or a cached data string
     */
    public function get($id, $group = null) {

        // On récupère le groupe.
        $group = isset($group) ? $group : $this->default_group;

        // On récupère l'élément.
        $item = $this->adapter->getItem($this->getCacheId($id, $group));

        // On retourne le résultat
        return $item->get();
    }

    /**
     * On récupère toutes les données mises en cache.
     *
     * @return  array data
     */
    public function getAll() {

        $items = [];
        $data  = $this->adapter->getItems();

        if (!empty($data)) {
            foreach ($data as $k => $v) {
                $items[$k] = $v->get();
            }
        }

        return $items;

    }

    /**
     * Stocke une donnée à mettre en cache avec un identifiant et un groupe.
     *
     * @param   mixed  $data  Les données à mettre en cache
     * @param   string $id    L'identifiant
     * @param   string $group Le groupe
     * @param   int    $ttl   Une durée de vie optionnelle.
     *
     * @return  boolean  True si les données ont été mises en cache.
     */
    public function set($data, $id, $group = null, $ttl = null) {

        if (!$this->lockIndex()) {
            return false;
        }

        // On récupère les paramètres.
        $group = isset($group) ? $group : $this->default_group;
        $ttl   = isset($ttl) ? $ttl : $this->ttl;
        $id    = $this->getCacheId($id, $group);

        // On crée l'élément de cache.
        $item = new Item($id, $ttl);
        $item->set($data);

        // On ajoute notre identifiant à l'index des clés.
        $this->appendToIndex($id);
        $this->unlockIndex();

        return $this->adapter->save($item);
    }

    /**
     * Supprime des données mise en cache par un identifiant et un groupe.
     *
     * @param   string $id    L'identifiant
     * @param   string $group Le groupe
     *
     * @return  boolean  True en cas de succès, false sinon
     */
    public function delete($id, $group = null) {

        if (!$this->lockIndex()) {
            return false;
        }

        // On récupère le groupe.
        $group = isset($group) ? $group : $this->default_group;
        $id    = $this->getCacheId($id, $group);error_log($id);

        $this->removeFromIndex($id);

        return $this->adapter->deleteItem($id);
    }

    /**
     * Clean cache for a group given a mode.
     *
     * @param   string $group The cache data group
     * @param   string $mode  The mode for cleaning cache [group|notgroup]
     *                        group mode    : cleans all cache in the group
     *                        notgroup mode : cleans all cache not in the group
     *
     * @return  boolean  True on success, false otherwise
     *
     * @since   12.1
     */
    public function clean($group, $mode = null) {

        if (!$this->lockIndex()) {
            return false;
        }

        $index = $this->getIndex();
        $arr   = $index->get();

        foreach ($arr as $k => $key) {
            if (strpos($key, $this->context . '-cache-' . $group . '-') === 0 xor $mode != 'group') {
                $this->adapter->deleteItem($key);
                unset($arr[$k]);
            }
        }

        $index->set($arr);
        $this->adapter->save($index);
        $this->unlockIndex();

        return true;
    }

    protected function getIndex() {

        $index = $this->adapter->getItem($this->context . '-index');

        if (!$index->isHit()) {
            $index->expiresAfter(999999999);
            $index->set([]);
        }

        return $index;
    }

    protected function appendToIndex($id) {

        $index = $this->getIndex();
        $arr   = $index->get();

        if (!in_array($id, $arr)) {
            $arr[] = $id;
            $index->set($arr);
            return $this->adapter->save($index);
        }

        return true;

    }

    protected function removeFromIndex($id) {

        $index = $this->getIndex();
        $arr   = $index->get();

        if (($key = array_search($id, $arr)) !== false) {
            unset($arr[$key]);
        }

        $index->set($arr);
        return $this->adapter->save($index);

    }

    /**
     * Lock cache index
     *
     * @return  boolean  True on success, false otherwise.
     */
    protected function lockIndex() {

        $looptime = 300;
        $lock = new Item($this->context . '-index_lock', 30);
        $lock->set(1);
        $data_lock = $this->adapter->save($lock);

        if ($data_lock === false) {
            $lock_counter = 0;

            // Loop until you find that the lock has been released.  that implies that data get from other thread has finished
            while ($data_lock === false) {
                if ($lock_counter > $looptime) {
                    return false;
                    break;
                }

                usleep(100);
                $data_lock = $this->adapter->save($lock);
                $lock_counter++;
            }
        }

        return true;
    }

    /**
     * Unlock cache index
     *
     * @return  boolean  True on success, false otherwise.
     *
     * @since   12.1
     */
    protected function unlockIndex() {

        return $this->adapter->deleteItem($this->context . '-index_lock');
    }

    /**
     * Donne un identifiant de mise en cache depuis une paire identifiant/groupe.
     *
     * @param   string $id    L'identifiant
     * @param   string $group Le groupe
     *
     * @return  string   L'identifiant
     */
    protected function getCacheId($id, $group) {

        return $this->context . '-cache-' . $group . '-' . $id;
    }

}