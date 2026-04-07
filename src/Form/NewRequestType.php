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
use App\Entity\Request as AccessRequest;

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
                'query_builder' => static fn(EntityRepository $repository) => $repository
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
                'required' => true,
                'choices' => [
                    'Ouverture - Nouveau collaborateur' => AccessRequest::TYPE_OUVERTURE,
                    'Modification - Changement de service ou fonction' => AccessRequest::TYPE_MODIFICATION,
                    'Fermeture - Départ du collaborateur' => AccessRequest::TYPE_FERMETURE,
                ]
            ])
            ->add('parentRequest', EntityType::class, [
                'class' => AccessRequest::class,
                'label' => 'Demande d’origine',
                'placeholder' => 'Sélectionner une demande d’origine',
                'required' => false,
                'choice_label' => static function (AccessRequest $request): string {
                    $agent = $request->getAgent();
                    $agentName = $agent ? trim(($agent->getFirstname() ?? '') . ' ' . ($agent->getLastname() ?? '')) : 'N/A';

                    return sprintf('%s - %s', $request->getReference(), $agentName);
                },
                'choice_attr' => static function (AccessRequest $request): array {
                    $agent = $request->getAgent();
                    $agentName = $agent ? trim(($agent->getFirstname() ?? '') . ' ' . ($agent->getLastname() ?? '')) : 'N/A';
                    $serviceName = ($agent && $agent->getService()) ? ($agent->getService()->getName() ?? 'N/A') : 'N/A';

                    $logicielIds = [];
                    $logicielNames = [];
                    $materielIds = [];
                    $materielNames = [];

                    foreach ($request->getRessources() as $ressource) {
                        $id = $ressource->getId();
                        $name = trim((string) $ressource->getName());

                        if ($ressource->getCategory() === 'logiciel') {
                            if ($id !== null) {
                                $logicielIds[] = (string) $id;
                            }
                            if ($name !== '') {
                                $logicielNames[] = $name;
                            }
                            continue;
                        }

                        if ($ressource->getCategory() === 'materiel') {
                            if ($id !== null) {
                                $materielIds[] = (string) $id;
                            }
                            if ($name !== '') {
                                $materielNames[] = $name;
                            }
                        }
                    }

                    return [
                        'data-agent-name'          => $agentName,
                        'data-service-name'         => $serviceName,
                        'data-civility'             => $agent ? ($agent->getCivility() ?? '') : '',
                        'data-firstname'            => $agent ? ($agent->getFirstname() ?? '') : '',
                        'data-lastname'             => $agent ? ($agent->getLastname() ?? '') : '',
                        'data-email'                => $agent ? ($agent->getEmail() ?? '') : '',
                        'data-job-title'            => $agent ? ($agent->getJobTitle() ?? '') : '',
                        'data-service-id'           => ($agent && $agent->getService()) ? (string) ($agent->getService()->getId() ?? '') : '',
                        'data-arrival-date-input'   => $request->getArrivalDate()?->format('Y-m-d') ?? '',
                        'data-departure-date-input' => $request->getDepartureDate()?->format('Y-m-d') ?? '',
                        'data-commentary'           => $request->getCommentary() ?? '',
                        'data-logiciel-ids'         => implode(',', $logicielIds),
                        'data-materiel-ids'         => implode(',', $materielIds),
                        'data-logiciel-names'       => implode('||', $logicielNames),
                        'data-materiel-names'       => implode('||', $materielNames),
                    ];
                },
                'query_builder' => static fn(EntityRepository $repository) => $repository
                    ->createQueryBuilder('r')
                    ->leftJoin('r.agent', 'a')->addSelect('a')
                    ->leftJoin('a.service', 's')->addSelect('s')
                    ->leftJoin('r.ressources', 're')->addSelect('re')
                    ->where('r.status = :status')
                    ->setParameter('status', AccessRequest::STATUS_TRAITEE)
                    ->orderBy('r.updateDate', 'DESC'),
                'help' => 'Obligatoire pour une modification ou une fermeture.',
            ])
            ->add('logiciels', EntityType::class, [
                'class' => Ressource::class,
                'label' => false,
                'choice_label' => 'name',
                'expanded' => true,
                'multiple' => true,
                'required' => false,
                'query_builder' => static fn(EntityRepository $repository) => $repository
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
                'query_builder' => static fn(EntityRepository $repository) => $repository
                    ->createQueryBuilder('r')
                    ->where('r.category = :category')
                    ->andWhere('r.isActive = :active')
                    ->setParameter('category', 'materiel')
                    ->setParameter('active', true)
                    ->orderBy('r.name', 'ASC'),
            ])
            ->add('commentary', TextareaType::class, [
                'label' => 'Commentaires',
                'required' => true,
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
