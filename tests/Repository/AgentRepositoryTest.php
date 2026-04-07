<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Agent;
use App\Entity\Service;
use App\Repository\AgentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentRepositoryTest extends KernelTestCase
{
	private EntityManagerInterface $em;
	private AgentRepository $repository;

	protected function setUp(): void
	{
		self::bootKernel();
		$this->em = self::getContainer()->get(EntityManagerInterface::class);
		$this->repository = self::getContainer()->get(AgentRepository::class);
	}

	public function testFindOneByIdentityMatchesCaseInsensitiveEmail(): void
	{
		$uniq = str_replace('.', '', uniqid('agent', true));
		$firstname = 'Jean' . $uniq;
		$lastname = 'Dupont' . $uniq;
		$email = 'JEAN.' . $uniq . '@example.local';

		$service = (new Service())
			->setName('Service Test ' . uniqid('', true))
			->setEmail('service' . uniqid('', true) . '@example.local');

		$agent = (new Agent())
			->setCivility('M.')
			->setFirstname($firstname)
			->setLastname($lastname)
			->setJobTitle('Technicien')
			->setEmail($email)
			->setService($service);

		$this->em->persist($service);
		$this->em->persist($agent);
		$this->em->flush();

		$found = $this->repository->findOneByIdentity('  ' . mb_strtolower($firstname) . '  ', '  ' . mb_strtoupper($lastname) . ' ', mb_strtolower($email));

		self::assertInstanceOf(Agent::class, $found);
		self::assertSame($agent->getId(), $found->getId());
	}

	public function testFindOneByIdentityMatchesWhenEmailIsEmpty(): void
	{
		$uniq = str_replace('.', '', uniqid('agent', true));
		$firstname = 'Alice' . $uniq;
		$lastname = 'Martin' . $uniq;

		$service = (new Service())
			->setName('Service Test ' . uniqid('', true))
			->setEmail('service' . uniqid('', true) . '@example.local');

		$agent = (new Agent())
			->setCivility('Mme')
			->setFirstname($firstname)
			->setLastname($lastname)
			->setJobTitle('Assistante')
			->setEmail(null)
			->setService($service);

		$this->em->persist($service);
		$this->em->persist($agent);
		$this->em->flush();

		$found = $this->repository->findOneByIdentity(mb_strtolower($firstname), mb_strtolower($lastname), '');

		self::assertInstanceOf(Agent::class, $found);
		self::assertSame($agent->getId(), $found->getId());
	}
}
