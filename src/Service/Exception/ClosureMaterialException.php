<?php

namespace App\Service\Exception;

/**
 * Exception métier dont le code porte le statut HTTP à restituer par le contrôleur.
 */
final class ClosureMaterialException extends \InvalidArgumentException
{
}