<?php

namespace App\Form;

use App\Entity\Ressource;
use App\Entity\Service;
use App\Form\Model\NewRequestData;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NewRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('civility', ChoiceType::class, [
                'label' => 'Civilité *',
                'placeholder' => 'Sélectionner une civilité',
                'choices' => [
                    'M.' => 'M.',
                    'Mme' => 'Mme',
                    'Mlle' => 'Mlle',
                ],
            ])
            ->add('firstname', TextType::class, [
                'label' => 'Prénom *',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'Nom *',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email *',
                'attr' => [
                    'placeholder' => 'prenom.nom@mairie.fr',
                ],
            ])
            ->add('service', EntityType::class, [
                'class' => Service::class,
                'label' => 'Service *',
                'placeholder' => 'Sélectionner un service',
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository) => $repository
                    ->createQueryBuilder('s')
                    ->orderBy('s.name', 'ASC'),
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'Fonction *',
            ])
            ->add('arrivalDate', DateType::class, [
                'label' => 'Date d’arrivée',
                'widget' => 'single_text',
                'input' => 'datetime',
                'required' => false,
            ])
            ->add('departureDate', DateType::class, [
                'label' => 'Date de départ',
                'widget' => 'single_text',
                'input' => 'datetime',
                'required' => false,
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type de demande *',
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Ouverture - Nouveau collaborateur' => 'ouverture',
                    'Modification - Changement de service ou fonction' => 'modification',
                    'Fermeture - Départ du collaborateur' => 'fermeture',
                ],
            ])
            ->add('logiciels', EntityType::class, [
                'class' => Ressource::class,
                'label' => false,
                'choice_label' => 'name',
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository) => $repository
                    ->createQueryBuilder('r')
                    ->where('r.category = :category')
                    ->andWhere('r.isActive = :active')
                    ->setParameter('category', 'logiciel')
                    ->setParameter('active', true)
                    ->orderBy('r.name', 'ASC'),
            ])
            ->add('materiels', EntityType::class, [
                'class' => Ressource::class,
                'label' => false,
                'choice_label' => 'name',
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository) => $repository
                    ->createQueryBuilder('r')
                    ->where('r.category = :category')
                    ->andWhere('r.isActive = :active')
                    ->setParameter('category', 'materiel')
                    ->setParameter('active', true)
                    ->orderBy('r.name', 'ASC'),
            ])
            ->add('commentary', TextareaType::class, [
                'label' => 'Commentaires',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Informations complémentaires, besoins spécifiques...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewRequestData::class,
        ]);
    }
}