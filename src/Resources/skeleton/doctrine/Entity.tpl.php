<?= "<?php\n" ?>

namespace <?= $namespace ?>;

use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Model as ORMBehaviors;
use <?= $entity_intreface_path ?>;
use <?= $abstract_entity_path ?>;
<?php if ($api_resource): ?>use ApiPlatform\Core\Annotation\ApiResource;
<?php endif ?>

/**
<?php if ($api_resource): ?> * @ApiResource()
<?php endif ?>
 * @ORM\Entity()
 * @ORM\HasLifecycleCallbacks
 * @ORM\Table(name="<?= $table_name ?>")
 */
class <?= $class_name ?> extends <?= $abstract_entity_name ?> implements <?= $entity_intreface_name ?>

{
<?php if ($is_translatable): ?>
    use ORMBehaviors\Translatable\Translatable;
<?php endif ?>

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    public function getId()
    {
        return $this->id;
    }
}
