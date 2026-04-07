<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewRequestControllerTest extends WebTestCase
{
	public function testNewRequestRedirectsToLoginForAnonymous(): void
	{
		$client = static::createClient();
		$client->request('GET', '/new/request');

		self::assertResponseRedirects('/login');
	}
}
