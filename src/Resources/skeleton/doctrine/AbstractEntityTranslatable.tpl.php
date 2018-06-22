<?= "<?php\n" ?>

namespace <?= $namespace ?>;

use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Model as ORMBehaviors;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\Common\Collections\ArrayCollection;

/**
* @MappedSuperclass
*/
abstract class <?= $class_name ?>

{
    use ORMBehaviors\Translatable\Translatable;
}
