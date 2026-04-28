# Mise en place d'un système de cache avec Redis

1. Placer le projet dans une VM Ubuntu
2. Installer Redis sur la VM

    2.1 Avec les commandes suivantes :
    sudo apt update
    sudo apt install redis-server
    sudo systemctl enable redis-server
    sudo systemctl start redis-server

3. vérifier le fonctionnement de Redis
    avec la commande :
    redis-cli ping
    <!-- Doit répondre : PONG -->

4. Installer l'extension PHP Redis
    avec les commandes:
    sudo apt install php-redis
    sudo systemctl restart php8.5-fpm # adapté la version

5. Vérifier le chargement de l'extension
    php -m | grep redis
    <!-- Doit afficher : redis -->

6. Installer les dépendances dans Symfony
    composer require symfony/cache symfony/redis-messenger

7. Configurer symfony pour utiliser Redis
    dans le fichier: config/packages/cache.yaml

    ```php
    framework:
        cache:
            app: cache.adapter.redis
            default_redis_provider:'redis://localhost'
    ```

8. Placer le cache dans le code partout ou c'est necessaire:
    Par exemple, dans un controller ou service:
        ```php
        <?php
        use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

public function index(CacheInterface $cache)
{
    $value = $cache->get('ma_cle', function (ItemInterface $item) {
        $item->expiresAfter(3600); // 1h
        return 'Ma valeur à mettre en cache';
    });
    <!-- ...utilise $value -->
}
    ```
