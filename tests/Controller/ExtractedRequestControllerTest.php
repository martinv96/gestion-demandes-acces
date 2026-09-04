<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExtractedRequestControllerTest extends WebTestCase
{
    /**
     * Vérifie que les routes conservées après extraction restent protégées par le firewall.
     * Les règles métier de chaque action sont ensuite exécutées uniquement pour un utilisateur connecté.
     */
    #[DataProvider('protectedRoutes')]
    public function testExtractedRouteRedirectsAnonymousUser(string $method, string $uri): void
    {
        $client = static::createClient();
        $client->request($method, $uri);

        self::assertResponseRedirects('/login');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRoutes(): iterable
    {
        yield 'retour de matériel' => ['POST', '/request/1/mark-returned/1'];
        yield 'commentaire privé DSI' => ['POST', '/request/1/private-comment-dsi/add'];
        yield 'suppression de demande' => ['POST', '/request/1/delete'];
        yield 'export des demandes' => ['GET', '/request/exportCsv'];
    }
}