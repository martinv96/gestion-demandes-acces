<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListRequestControllerTest extends WebTestCase
{
	public function testListRequestRedirectsToLoginForAnonymous(): void
	{
		$client = static::createClient();
		$client->request('GET', '/list/request');

		self::assertResponseRedirects('/login');
	}
}
