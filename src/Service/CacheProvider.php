<?php
/**
 * Part of the ETD Framework Cache Package
 *
 * @copyright   Copyright (C) 2016 ETD Solutions, SARL Etudoo. Tous droits réservés.
 * @license     Apache License 2.0; see LICENSE
 * @author      ETD Solutions http://etd-solutions.com
 */

namespace EtdSolutions\Cache\Service;

use EtdSolutions\Cache\Cache;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Fournisseur du service Cache
 */
class CacheProvider implements ServiceProviderInterface {

    /**
     * Enregistre le fournisseur de service auprès du container DI.
     *
     * @param Container $container Le container DI.
     *
     * @return Container Retourne l'instance pour le chainage.
     */
    public function register(Container $container) {

        $container->set('EtdSolutions\\Cache\\Cache', function () use ($container) {

            return new Cache($container);

        }, true, true);

        // On crée un alias pour le cache.
        $container->alias('cache', 'EtdSolutions\\Cache\\Cache');
    }
}
