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
<?php if ($is_translatable): ?>
   // use ORMBehaviors\Translatable\Translatable;
<?php endif ?>

}
