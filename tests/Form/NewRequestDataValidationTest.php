<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Service;
use App\Form\Model\NewRequestData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;


// la classe hérite de kernelTestCase, demarre symfony pour récupérer le vrai service de validation.
final class NewRequestDataValidationTest extends KernelTestCase
{
    // Validator pour valider les données du formulaire selon les contraintes définies dans NewRequestData.
    private ValidatorInterface $validator;

    /* 
     dans setUp, le test récupère validatorInterface depuis le container de Symfony 
     pour pouvoir valider les données du formulaire 
     comme le ferait Symfony lors de la soumission du formulaire.
    */
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    /* 
        Teste que des données valides ne génèrent aucune violation de contrainte.
        créer un objet valide, puis vérifie 0 erreur.
    */
    public function testValidDataHasNoViolation(): void
    {
        $data = $this->createValidData();

        $violations = $this->validator->validate($data);

        self::assertCount(0, $violations);
    }

    /* 
        Teste que le type de demande doit être soit "ouverture" soit "fermeture".
        créer un objet valide, puis modifie le type pour "fermeture" sans date de départ.
    */
    public function testFermetureRequiresDepartureDate(): void
    {
        $data = $this->createValidData();
        $data->setType('fermeture');
        $data->setDepartureDate(null);

        $violations = $this->validator->validate($data);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('parentRequest', $violations[0]->getPropertyPath());
        self::assertSame("La demande d’origine est obligatoire pour une modification ou une fermeture.", $violations[0]->getMessage());
    }

    public function testOuvertureRequiresArrivalDate(): void
    {
        $data = $this->createValidData();
        $data->setType('ouverture');
        $data->setArrivalDate(null);

        $violations = $this->validator->validate($data);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('arrivalDate', $violations[0]->getPropertyPath());
        self::assertSame('La date d’arrivée est obligatoire.', $violations[0]->getMessage());
    }

    /* 
        Teste que la date de départ ne peut pas être antérieure à la date d'arrivée.
        créer un objet valide, puis modifie les dates pour que la date de départ soit antérieure à la date d'arrivée.
    */
    public function testDepartureDateMustBeAfterArrivalDate(): void
    {
        $data = $this->createValidData();
        $data->setArrivalDate(new \DateTime('2026-06-10'));
        $data->setDepartureDate(new \DateTime('2026-06-01'));

        $violations = $this->validator->validate($data);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame('departureDate', $violations[0]->getPropertyPath());
        self::assertSame('La date de départ ne peut pas être antérieure à la date d’arrivée.', $violations[0]->getMessage());
    }

    /* 
        Teste que le champ email doit être une adresse email valide.
        créer un objet valide, puis modifie l'email pour qu'il soit invalide.
    */
    public function testInvalidEmailTriggersViolation(): void
    {
        $data = $this->createValidData();
        $data->setEmail('email-invalide');

        $violations = $this->validator->validate($data);

        self::assertGreaterThan(0, $violations->count());

        $foundEmailViolation = false;
        foreach ($violations as $violation) {
            if ($violation->getPropertyPath() === 'email') {
                $foundEmailViolation = true;
                break;
            }
        }

        self::assertTrue($foundEmailViolation, 'Une violation sur le champ email est attendue.');
    }

    /* 
        Méthode utilitaire pour créer un objet NewRequestData avec des données valides par défaut.
    */
    private function createValidData(): NewRequestData
    {
        $service = new Service();
        $service->setName('Direction SI');
        $service->setEmail('dsi@mairie.fr');

        $data = new NewRequestData();
        $data->setCivility('M.');
        $data->setFirstname('Jean');
        $data->setLastname('Dupont');
        $data->setEmail('jean.dupont@mairie.fr');
        $data->setService($service);
        $data->setJobTitle('Technicien');
        $data->setArrivalDate(new \DateTime('2026-06-01'));
        $data->setDepartureDate(null);
        $data->setType('ouverture');
        $data->setCommentary('Demande de test.');

        return $data;
    }
}
