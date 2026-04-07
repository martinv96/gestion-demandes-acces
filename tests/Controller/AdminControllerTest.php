<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
	public function testAdminIndexRedirectsToLoginForAnonymous(): void
	{
		$client = static::createClient();
		$client->request('GET', '/admin');

		self::assertResponseRedirects('/login');
	}

	public function testAdminUserAddRedirectsToLoginForAnonymous(): void
	{
		$client = static::createClient();
		$client->request('POST', '/admin/user/add');

		self::assertResponseRedirects('/login');
	}
}

