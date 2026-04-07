<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\Model\NewRequestData;
use App\Form\NewRequestType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class NewRequestTypeTest extends KernelTestCase
{
	private FormFactoryInterface $formFactory;

	protected function setUp(): void
	{
		self::bootKernel();
		$this->formFactory = self::getContainer()->get('form.factory');
	}

	public function testBuildFormContainsExpectedFields(): void
	{
		$form = $this->formFactory->create(NewRequestType::class, new NewRequestData());

		self::assertTrue($form->has('civility'));
		self::assertTrue($form->has('firstname'));
		self::assertTrue($form->has('lastname'));
		self::assertTrue($form->has('email'));
		self::assertTrue($form->has('service'));
		self::assertTrue($form->has('jobTitle'));
		self::assertTrue($form->has('arrivalDate'));
		self::assertTrue($form->has('departureDate'));
		self::assertTrue($form->has('type'));
		self::assertTrue($form->has('parentRequest'));
		self::assertTrue($form->has('logiciels'));
		self::assertTrue($form->has('materiels'));
		self::assertTrue($form->has('commentary'));
	}

	public function testConfigureOptionsUsesNewRequestDataClass(): void
	{
		$form = $this->formFactory->create(NewRequestType::class, new NewRequestData());

		self::assertSame(NewRequestData::class, $form->getConfig()->getOption('data_class'));
	}

	public function testParentRequestFieldIsOptional(): void
	{
		$form = $this->formFactory->create(NewRequestType::class, new NewRequestData());

		self::assertFalse($form->get('parentRequest')->getConfig()->getOption('required'));
	}
}
