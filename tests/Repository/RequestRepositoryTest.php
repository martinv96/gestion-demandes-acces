<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Service;
use App\Entity\User;
use App\Repository\RequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestRepositoryTest extends KernelTestCase
{
	private EntityManagerInterface $em;
	private RequestRepository $repository;

	protected function setUp(): void
	{
		self::bootKernel();
		$this->em = self::getContainer()->get(EntityManagerInterface::class);
		$this->repository = self::getContainer()->get(RequestRepository::class);
	}

	public function testFindLatestProcessedReplacementChildReturnsMostRecentChild(): void
	{
		$context = $this->createAuthorAndAgent('latest');
		$parent = $this->createRequest('ouverture', 'en_attente_rh', $context['agent'], $context['author']);
		$childOld = $this->createRequest('modification', 'traitee', $context['agent'], $context['author'], $parent, '-2 day');
		$childNew = $this->createRequest('fermeture', 'traitee', $context['agent'], $context['author'], $parent, 'now');

		$this->em->flush();

		$found = $this->repository->findLatestProcessedReplacementChild($parent);

		self::assertInstanceOf(AccessRequest::class, $found);
		self::assertSame($childNew->getId(), $found->getId());
		self::assertNotSame($childOld->getId(), $found->getId());
	}

	public function testFindCurrentInChainReturnsLastProcessedRequest(): void
	{
		$context = $this->createAuthorAndAgent('chain');
		$root = $this->createRequest('ouverture', 'en_attente_rh', $context['agent'], $context['author']);
		$child1 = $this->createRequest('modification', 'traitee', $context['agent'], $context['author'], $root, '-1 day');
		$child2 = $this->createRequest('fermeture', 'traitee', $context['agent'], $context['author'], $child1, 'now');

		$this->em->flush();

		$current = $this->repository->findCurrentInChain($root);

		self::assertSame($child2->getId(), $current->getId());
	}

	public function testFindActiveCurrentRequestForAgentIdentityReturnsActiveRequest(): void
	{
		$context = $this->createAuthorAndAgent('active');
		$request = $this->createRequest('ouverture', 'en_attente_rh', $context['agent'], $context['author']);

		$this->em->flush();

		$found = $this->repository->findActiveCurrentRequestForAgentIdentity(
			(string) $context['agent']->getFirstname(),
			(string) $context['agent']->getLastname(),
			(string) $context['agent']->getEmail()
		);

		self::assertInstanceOf(AccessRequest::class, $found);
		self::assertSame($request->getId(), $found->getId());
	}

	public function testFindActiveCurrentRequestForAgentIdentityReturnsNullForClosedRequest(): void
	{
		$context = $this->createAuthorAndAgent('closed');
		$this->createRequest('fermeture', 'traitee', $context['agent'], $context['author']);

		$this->em->flush();

		$found = $this->repository->findActiveCurrentRequestForAgentIdentity(
			(string) $context['agent']->getFirstname(),
			(string) $context['agent']->getLastname(),
			(string) $context['agent']->getEmail()
		);

		self::assertNull($found);
	}

	/**
	 * @return array{author: User, agent: Agent}
	 */
	private function createAuthorAndAgent(string $suffix): array
	{
		$uniq = str_replace('.', '', uniqid($suffix, true));

		$service = (new Service())
			->setName('Service-' . $uniq)
			->setEmail('service-' . $uniq . '@example.local');

		$author = (new User())
			->setEmail('author-' . $uniq . '@example.local')
			->setPassword('hashed-password')
			->setIsActive(true)
			->setMustChangePassword(false)
			->setService($service);

		$agent = (new Agent())
			->setCivility('M.')
			->setFirstname('Agent' . $uniq)
			->setLastname('Test')
			->setJobTitle('Tech')
			->setEmail('agent-' . $uniq . '@example.local')
			->setService($service);

		$this->em->persist($service);
		$this->em->persist($author);
		$this->em->persist($agent);

		return ['author' => $author, 'agent' => $agent];
	}

	private function createRequest(
		string $type,
		string $status,
		Agent $agent,
		User $author,
		?AccessRequest $parent = null,
		string $updateDate = 'now'
	): AccessRequest {
		$request = (new AccessRequest())
			->setType($type)
			->setStatus($status)
			->setAgent($agent)
			->setAuthor($author)
			->setCommentary('test')
			->setArrivalDate(new \DateTime('2026-04-01'))
			->setCreationDate(new \DateTimeImmutable('2026-04-01 10:00:00'))
			->setUpdateDate(new \DateTimeImmutable($updateDate));

		if ($parent instanceof AccessRequest) {
			$request->setParentRequest($parent);
		}

		$this->em->persist($request);

		return $request;
	}
}
