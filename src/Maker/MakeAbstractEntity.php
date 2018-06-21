<?php

/*
 * This file is part of the Symfony MakerBundle package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MakerBundle\Maker;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Doctrine\DoctrineHelper;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputAwareMakerInterface;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\MakerInterface;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Doctrine\EntityRegenerator;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Util\ClassSourceManipulator;
use Symfony\Bundle\MakerBundle\Doctrine\EntityRelation;
use Symfony\Bundle\MakerBundle\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Ryan Weaver <weaverryan@gmail.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class MakeAbstractEntity extends AbstractMaker implements MakerInterface
{
    private $fileManager;
    private $doctrineHelper;
    private $generator;
    private $taplePrefix = 'jm';

    public function __construct(FileManager $fileManager, DoctrineHelper $doctrineHelper, string $projectDirectory, Generator $generator = null)
    {
        $this->fileManager = $fileManager;
        $this->doctrineHelper = $doctrineHelper;
        // $projectDirectory is unused, argument kept for BC

        if (null === $generator) {
            @trigger_error(sprintf('Passing a "%s" instance as 4th argument is mandatory since version 1.5.', Generator::class), E_USER_DEPRECATED);
            $this->generator = new Generator($fileManager, 'Appaa\\');
        } else {
            $this->generator = $generator;
        }
    }

    public static function getCommandName(): string
    {
        return 'make:abstract-entity';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConf)
    {
        $command
            ->setDescription('Creates or updates a Doctrine entity class, and optionally an API Platform resource')
            ->addArgument('name', InputArgument::OPTIONAL, sprintf('Class name of the entity to create or update (e.g. <fg=yellow>%s</>)', Str::asClassName(Str::getRandomTerm())))
            ->addOption('api-resource', 'a', InputOption::VALUE_NONE, 'Mark this class as an API Platform resource (expose a CRUD API for it)')
            ->addOption('regenerate', null, InputOption::VALUE_NONE, 'Instead of adding new fields, simply generate the methods (e.g. getter/setter) for existing fields')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite any existing getter/setter methods')
            ->addOption('translation', null, InputOption::VALUE_NONE,'add entity translation table for this)')
            ->setHelp(file_get_contents(__DIR__.'/../Resources/help/MakeEntity.txt'))
        ;

        $inputConf->setArgumentAsNonInteractive('name');
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command)
    {
        if ($input->getArgument('name')) {
            return;
        }

        if ($input->getOption('regenerate')) {
            $io->block([
                'This command will generate any missing methods (e.g. getters & setters) for a class or all classes in a namespace.',
                'To overwrite any existing methods, re-run this command with the --overwrite flag',
            ], null, 'fg=yellow');
            $classOrNamespace = $io->ask('Enter a class or namespace to regenerate', $this->getEntityNamespace(), [Validator::class, 'notBlank']);

            $input->setArgument('name', $classOrNamespace);

            return;
        }

        $entityFinder = $this->fileManager->createFinder('src/Entity/')
            // remove if/when we allow entities in subdirectories
            ->depth('<1')
            ->name('*.php');
        $classes = [];
        /** @var SplFileInfo $item */
        foreach ($entityFinder as $item) {
            if (!$item->getRelativePathname()) {
                continue;
            }

            $classes[] = str_replace(['.php', '/'], ['', '\\'], $item->getRelativePathname());
        }

        $argument = $command->getDefinition()->getArgument('name');
        $question = $this->createEntityClassQuestion($argument->getDescription());
        $value = $io->askQuestion($question);

        $input->setArgument('name', $value);

        $description = $command->getDefinition()->getOption('translation')->getDescription();
        $question = new ConfirmationQuestion($description, false);
        $travalue = $io->askQuestion($question);

        $input->setOption('translation', $travalue);

        if (
            !$input->getOption('api-resource') &&
            class_exists(ApiResource::class) &&
            !class_exists($this->generator->createClassNameDetails($value, 'Entity\\')->getFullName())
        ) {
            $description = $command->getDefinition()->getOption('api-resource')->getDescription();
            $question = new ConfirmationQuestion($description, false);
            $value = $io->askQuestion($question);

            $input->setOption('api-resource', $value);
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator)
    {
        if (\PHP_VERSION_ID < 70100) {
            throw new RuntimeCommandException('The make:entity command requires that you use PHP 7.1 or higher.');
        }

        $overwrite = $input->getOption('overwrite');

        // the regenerate option has entirely custom behavior
        if ($input->getOption('regenerate')) {
            $this->regenerateEntities($input->getArgument('name'), $overwrite, $generator);
            $this->writeSuccessMessage($io);

            return;
        }

        $entityClassDetails = $generator->createClassNameDetails(
            $input->getArgument('name'),
            'Entity\\'
        );

        $repositoryClassDetails = $generator->createClassNameDetails(
            $entityClassDetails->getRelativeName(),
            'Repository\\',
            'Repository'
        );

        $classExists = class_exists($entityClassDetails->getFullName());
        if (!$classExists) {
            $entityPath = $generator->generateClass(
                $entityClassDetails->getFullName(),
                'doctrine/Entity.tpl.php',
                [
                    'repository_full_class_name' => $repositoryClassDetails->getFullName(),
                    'api_resource' => $input->getOption('api-resource'),
                    'table_name' => $this->taplePrefix.'_'.Str::asSnakeCase($input->getArgument('name')),
                    'is_translatable' => $this->taplePrefix.'_'.Str::asSnakeCase($input->getArgument('name')),

                ]
            );

            $entityAlias = strtolower($entityClassDetails->getShortName()[0]);
            $generator->generateClass(
                $repositoryClassDetails->getFullName(),
                'doctrine/Repository.tpl.php',
                [
                    'entity_full_class_name' => $entityClassDetails->getFullName(),
                    'entity_class_name' => $entityClassDetails->getShortName(),
                    'entity_alias' => $entityAlias,
                ]
            );


        }


        if ($input->getOption('translation')) {
            $transentityClassDetails = $generator->createClassNameDetails(
                $input->getArgument('name').' translation',
                'Entity\\Translation'
            );

            $classExists = class_exists($transentityClassDetails->getFullName());
            if (!$classExists) {
                $entityPath = $generator->generateClass(
                    $transentityClassDetails->getFullName(),
                    'doctrine/EntityTranslatable.tpl.php',
                    [
                        'api_resource' => $input->getOption('api-resource'),
                        'table_name' => $this->taplePrefix.'_'.Str::asSnakeCase($input->getArgument('name').' translation'),
                    ]
                );
            }
        }


        $generator->writeChanges();

        if (!$this->doesEntityUseAnnotationMapping($entityClassDetails->getFullName())) {
            throw new RuntimeCommandException(sprintf('Only annotation mapping is supported by make:entity, but the <info>%s</info> class uses a different format. If you would like this command to generate the properties & getter/setter methods, add your mapping configuration, and then re-run this command with the <info>--regenerate</info> flag.', $entityClassDetails->getFullName()));
        }

        if ($classExists) {
            $entityPath = $this->getPathOfClass($entityClassDetails->getFullName());
            $io->text([
                'Your entity already exists! So let\'s add some new fields!',
            ]);
        } else {
            $io->text([
                '',
                'Entity generated! Now let\'s add some fields!',
                'You can always add more fields later manually or by re-running this command.',
            ]);
        }

        $this->writeSuccessMessage($io);
        $io->text([
            'Next: When you\'re ready, create a migration with <comment>make:migration</comment>',
            '',
        ]);
    }

    public function configureDependencies(DependencyBuilder $dependencies, InputInterface $input = null)
    {
        if (null !== $input && $input->getOption('api-resource')) {
            $dependencies->addClassDependency(
                ApiResource::class,
                'api'
            );
        }

        // guarantee DoctrineBundle
        $dependencies->addClassDependency(
            DoctrineBundle::class,
            'orm'
        );

        // guarantee ORM
        $dependencies->addClassDependency(
            Column::class,
            'orm'
        );
    }



    private function createEntityClassQuestion(string $questionText): Question
    {
        $entityFinder = $this->fileManager->createFinder('src/Entity/')
            // remove if/when we allow entities in subdirectories
            ->depth('<1')
            ->name('*.php');
        $classes = [];
        /** @var SplFileInfo $item */
        foreach ($entityFinder as $item) {
            if (!$item->getRelativePathname()) {
                continue;
            }

            $classes[] = str_replace('/', '\\', str_replace('.php', '', $item->getRelativePathname()));
        }

        $question = new Question($questionText);
        $question->setValidator([Validator::class, 'notBlank']);
        $question->setAutocompleterValues($classes);

        return $question;
    }

    private function getPathOfClass(string $class): string
    {
        return (new \ReflectionClass($class))->getFileName();
    }

    private function regenerateEntities(string $classOrNamespace, bool $overwrite, Generator $generator)
    {
        $regenerator = new EntityRegenerator($this->doctrineHelper, $this->fileManager, $generator, $overwrite);
        $regenerator->regenerateEntities($classOrNamespace);
    }

    private function doesEntityUseAnnotationMapping(string $className): bool
    {
        if (!class_exists($className)) {
            $otherClassMetadatas = $this->doctrineHelper->getMetadata(Str::getNamespace($className), true);

            // if we have no metadata, we should assume this is the first class being mapped
            if (empty($otherClassMetadatas)) {
                return false;
            }

            $className = reset($otherClassMetadatas)->getName();
        }

        $driver = $this->doctrineHelper->getMappingDriverForClass($className);

        return $driver instanceof AnnotationDriver;
    }

    private function getEntityNamespace(): string
    {
        return $this->doctrineHelper->getEntityNamespace();
    }
}
