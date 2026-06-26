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

    public function testFindLatestProcessedReplacementChildReturnsMostRecentReplacementEvenWhenPending(): void
    {
        $context = $this->createAuthorAndAgent('latest');
        $parent = $this->createRequest('ouverture', 'en_attente_rh', $context['agent'], $context['author']);
        $childOld = $this->createRequest('modification', 'traitee', $context['agent'], $context['author'], $parent, '-2 day');
        $childNew = $this->createRequest('fermeture', 'en_attente_validation', $context['agent'], $context['author'], $parent, 'now');

        $this->em->flush();

        $found = $this->repository->findLatestProcessedReplacementChild($parent);

        self::assertInstanceOf(AccessRequest::class, $found);
        self::assertSame($childNew->getId(), $found->getId());
        self::assertNotSame($childOld->getId(), $found->getId());
    }

    public function testFindCurrentInChainReturnsLatestRequestInChain(): void
    {
        $context = $this->createAuthorAndAgent('chain');
        $root = $this->createRequest('ouverture', 'en_attente_rh', $context['agent'], $context['author']);
        $child1 = $this->createRequest('modification', 'traitee', $context['agent'], $context['author'], $root, '-1 day');
        $child2 = $this->createRequest('fermeture', 'en_attente_validation', $context['agent'], $context['author'], $child1, 'now');

        $this->em->flush();

        $current = $this->repository->findCurrentInChain($root);

        self::assertSame($child2->getId(), $current->getId());
    }

    public function testFindCurrentWithFiltersHidesParentWhenPendingReplacementExists(): void
    {
        $context = $this->createAuthorAndAgent('scope');
        $parent = $this->createRequest('ouverture', 'en_attente_validation', $context['agent'], $context['author']);
        $child = $this->createRequest('modification', 'en_attente_validation', $context['agent'], $context['author'], $parent, 'now');

        $this->em->flush();

        $results = $this->repository->findCurrentWithFilters([
            'agent' => $context['agent_info']['firstname'],
        ], 100, 0);

        $resultIds = array_map(static fn (AccessRequest $request): ?int => $request->getId(), $results);

        self::assertContains($child->getId(), $resultIds);
        self::assertNotContains($parent->getId(), $resultIds);
    }

    public function testFindActiveCurrentRequestForAgentIdentityReturnsActiveRequest(): void
    {
        $context = $this->createAuthorAndAgent('active');
        $request = $this->createRequest('ouverture', 'en_attente_rh', $context['agent'], $context['author']);

        $this->em->flush();

        $found = $this->repository->findActiveCurrentRequestForAgentIdentity(
            $context['agent_info']['firstname'],
            $context['agent_info']['lastname'],
            $context['agent_info']['email']
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
            $context['agent_info']['firstname'],
            $context['agent_info']['lastname'],
            $context['agent_info']['email']
        );

        self::assertNull($found);
    }

    /**
     * @return array{author: User, agent: Agent, agent_info: array{firstname: string, lastname: string, email: string}}
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

        if (method_exists($author, 'setFirstname')) { $author->setFirstname('Auth-' . $uniq); }
        if (method_exists($author, 'setFirstName')) { $author->setFirstName('Auth-' . $uniq); }
        if (method_exists($author, 'setLastname')) { $author->setLastname('Author'); }
        if (method_exists($author, 'setLastName')) { $author->setLastName('Author'); }
        if (method_exists($author, 'setCivility')) { $author->setCivility('M'); }

        $agent = new Agent();
        
        if (method_exists($agent, 'setFirstname')) {
            $agent->setFirstname('Agent' . $uniq);
        } elseif (method_exists($agent, 'setFirstName')) {
            $agent->setFirstName('Agent' . $uniq);
        }

        if (method_exists($agent, 'setLastname')) {
            $agent->setLastname('Test');
        } elseif (method_exists($agent, 'setLastName')) {
            $agent->setLastName('Test');
        }
        
        if (method_exists($agent, 'setEmail')) {
            $agent->setEmail('agent-' . $uniq . '@example.local');
        }
        if (method_exists($agent, 'setService')) {
            $agent->setService($service);
        }

        if (method_exists($agent, 'setCivility')) {
            $agent->setCivility('M');
        }

        // Gestion du job_title pour l'agent
        if (method_exists($agent, 'setJobTitle')) {
            $agent->setJobTitle('Developer');
        } elseif (method_exists($agent, 'setJobtitle')) {
            $agent->setJobtitle('Developer');
        }

        $this->em->persist($service);
        $this->em->persist($author);
        $this->em->persist($agent);

        return [
            'author' => $author,
            'agent' => $agent,
            'agent_info' => [
                'firstname' => 'Agent' . $uniq,
                'lastname' => 'Test',
                'email' => 'agent-' . $uniq . '@example.local'
            ]
        ];
    }

    /**
     * Crée et persiste une entité Request valide selon ton entité réelle.
     */
    private function createRequest(
        string $type,
        string $status,
        Agent $agent,
        User $author,
        ?AccessRequest $parent = null,
        string $dateModifier = 'now'
    ): AccessRequest {
        $request = (new AccessRequest())
            ->setType($type)
            ->setStatus($status)
			->setUpdateDate(new \DateTimeImmutable($dateModifier))
            ->setArrivalDate(new \DateTime($dateModifier))
            ->setCreationDate(new \DateTimeImmutable($dateModifier))
            ->setAgent($agent)
            ->setAuthor($author);

        if ($parent !== null) {
            $request->setParentRequest($parent);
        }

        $this->em->persist($request);

        return $request;
    }
}