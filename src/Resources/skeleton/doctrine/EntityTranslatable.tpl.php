<?= "<?php\n" ?>

namespace <?= $namespace ?>;

<?php if ($api_resource): ?>use ApiPlatform\Core\Annotation\ApiResource;
<?php endif ?>
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Model as ORMBehaviors;

/**
<?php if ($api_resource): ?> * @ApiResource()
<?php endif ?>

 * @ORM\Table(name="<?= $table_name ?>")
 */
class <?= $class_name."\n" ?>
{
    use ORMBehaviors\Translatable\Translation;

}
