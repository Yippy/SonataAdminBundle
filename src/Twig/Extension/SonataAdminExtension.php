<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Twig\Extension;

use Doctrine\Common\Util\ClassUtils;
use Psr\Log\LoggerInterface;
use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Admin\FieldDescriptionInterface;
use Sonata\AdminBundle\Admin\Pool;
use Sonata\AdminBundle\Exception\NoValueException;
use Symfony\Component\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\AbstractExtension;
use Twig\Template;
use Twig\TwigFilter;

/**
 * @author Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class SonataAdminExtension extends AbstractExtension
{
    /**
     * @var Pool
     */
    protected $pool;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var TranslatorInterface|null
     */
    protected $translator;

    /**
     * @var string[]
     */
    private $xEditableTypeMapping = [];

    public function __construct(Pool $pool, LoggerInterface $logger = null, TranslatorInterface $translator = null)
    {
        // NEXT_MAJOR: make the translator parameter required
        if (null === $translator) {
            @trigger_error(
                'The $translator parameter will be required fields with the 4.0 release.',
                E_USER_DEPRECATED
            );
        }
        $this->pool = $pool;
        $this->logger = $logger;
        $this->translator = $translator;
    }

    public function getFilters()
    {
        return [
            new TwigFilter(
                'render_list_element',
                [$this, 'renderListElement'],
                [
                    'is_safe' => ['html'],
                    'needs_environment' => true,
                ]
            ),
            new TwigFilter(
                'render_view_element',
                [$this, 'renderViewElement'],
                [
                    'is_safe' => ['html'],
                    'needs_environment' => true,
                ]
            ),
            new TwigFilter(
                'render_view_element_compare',
                [$this, 'renderViewElementCompare'],
                [
                    'is_safe' => ['html'],
                    'needs_environment' => true,
                ]
            ),
            new TwigFilter(
                'render_relation_element',
                [$this, 'renderRelationElement']
            ),
            new TwigFilter(
                'sonata_urlsafeid',
                [$this, 'getUrlsafeIdentifier']
            ),
            new TwigFilter(
                'sonata_xeditable_type',
                [$this, 'getXEditableType']
            ),
            new TwigFilter(
                'sonata_xeditable_choices',
                [$this, 'getXEditableChoices']
            ),
        ];
    }

    public function getName()
    {
        return 'sonata_admin';
    }

    /**
     * render a list element from the FieldDescription.
     *
     * @param mixed $object
     * @param array $params
     *
     * @return string
     */
    public function renderListElement(
        Environment $environment,
        $object,
        FieldDescriptionInterface $fieldDescription,
        $params = []
    ) {
        $template = $this->getTemplate(
            $fieldDescription,
            $fieldDescription->getAdmin()->getTemplate('base_list_field'),
            $environment
        );

        return $this->output($fieldDescription, $template, array_merge($params, [
            'admin' => $fieldDescription->getAdmin(),
            'object' => $object,
            'value' => $this->getValueFromFieldDescription($object, $fieldDescription),
            'field_description' => $fieldDescription,
        ]), $environment);
    }

    /**
     * @return string
     */
    public function output(
        FieldDescriptionInterface $fieldDescription,
        Template $template,
        array $parameters,
        Environment $environment
    ) {
        $content = $template->render($parameters);

        if ($environment->isDebug()) {
            $commentTemplate = <<<'EOT'

<!-- START
    fieldName: %s
    template: %s
    compiled template: %s
    -->
    %s
<!-- END - fieldName: %s -->
EOT;

            return sprintf(
                $commentTemplate,
                $fieldDescription->getFieldName(),
                $fieldDescription->getTemplate(),
                $template->getTemplateName(),
                $content,
                $fieldDescription->getFieldName()
            );
        }

        return $content;
    }

    /**
     * return the value related to FieldDescription, if the associated object does no
     * exists => a temporary one is created.
     *
     * @param object $object
     *
     * @throws \RuntimeException
     *
     * @return mixed
     */
    public function getValueFromFieldDescription(
        $object,
        FieldDescriptionInterface $fieldDescription,
        array $params = []
    ) {
        if (isset($params['loop']) && $object instanceof \ArrayAccess) {
            throw new \RuntimeException('remove the loop requirement');
        }

        $value = null;

        try {
            $value = $fieldDescription->getValue($object);
        } catch (NoValueException $e) {
            if ($fieldDescription->getAssociationAdmin()) {
                $value = $fieldDescription->getAssociationAdmin()->getNewInstance();
            }
        }

        return $value;
    }

    /**
     * render a view element.
     *
     * @param mixed $object
     *
     * @return string
     */
    public function renderViewElement(
        Environment $environment,
        FieldDescriptionInterface $fieldDescription,
        $object
    ) {
        $template = $this->getTemplate(
            $fieldDescription,
            'SonataAdminBundle:CRUD:base_show_field.html.twig',
            $environment
        );

        try {
            $value = $fieldDescription->getValue($object);
        } catch (NoValueException $e) {
            $value = null;
        }

        return $this->output($fieldDescription, $template, [
            'field_description' => $fieldDescription,
            'object' => $object,
            'value' => $value,
            'admin' => $fieldDescription->getAdmin(),
        ], $environment);
    }

    /**
     * render a compared view element.
     *
     * @param mixed $baseObject
     * @param mixed $compareObject
     *
     * @return string
     */
    public function renderViewElementCompare(
        Environment $environment,
        FieldDescriptionInterface $fieldDescription,
        $baseObject,
        $compareObject
    ) {
        $template = $this->getTemplate(
            $fieldDescription,
            'SonataAdminBundle:CRUD:base_show_field.html.twig',
            $environment
        );

        try {
            $baseValue = $fieldDescription->getValue($baseObject);
        } catch (NoValueException $e) {
            $baseValue = null;
        }

        try {
            $compareValue = $fieldDescription->getValue($compareObject);
        } catch (NoValueException $e) {
            $compareValue = null;
        }

        $baseValueOutput = $template->render([
            'admin' => $fieldDescription->getAdmin(),
            'field_description' => $fieldDescription,
            'value' => $baseValue,
        ]);

        $compareValueOutput = $template->render([
            'field_description' => $fieldDescription,
            'admin' => $fieldDescription->getAdmin(),
            'value' => $compareValue,
        ]);

        // Compare the rendered output of both objects by using the (possibly) overridden field block
        $isDiff = $baseValueOutput !== $compareValueOutput;

        return $this->output($fieldDescription, $template, [
            'field_description' => $fieldDescription,
            'value' => $baseValue,
            'value_compare' => $compareValue,
            'is_diff' => $isDiff,
            'admin' => $fieldDescription->getAdmin(),
        ], $environment);
    }

    /**
     * @param mixed $element
     *
     * @throws \RuntimeException
     *
     * @return mixed
     */
    public function renderRelationElement($element, FieldDescriptionInterface $fieldDescription)
    {
        if (!is_object($element)) {
            return $element;
        }

        $propertyPath = $fieldDescription->getOption('associated_property');

        if (null === $propertyPath) {
            // For BC kept associated_tostring option behavior
            $method = $fieldDescription->getOption('associated_tostring');

            if ($method) {
                @trigger_error(
                    'Option "associated_tostring" is deprecated since version 2.3 and will be removed in 4.0. '
                    .'Use "associated_property" instead.',
                    E_USER_DEPRECATED
                );
            } else {
                $method = '__toString';
            }

            if (!method_exists($element, $method)) {
                throw new \RuntimeException(sprintf(
                    'You must define an `associated_property` option or '.
                    'create a `%s::__toString` method to the field option %s from service %s is ',
                    get_class($element),
                    $fieldDescription->getName(),
                    $fieldDescription->getAdmin()->getCode()
                ));
            }

            return call_user_func([$element, $method]);
        }

        if (is_callable($propertyPath)) {
            return $propertyPath($element);
        }

        return $this->pool->getPropertyAccessor()->getValue($element, $propertyPath);
    }

    /**
     * Get the identifiers as a string that is safe to use in a url.
     *
     * @param object $model
     *
     * @return string string representation of the id that is safe to use in a url
     */
    public function getUrlsafeIdentifier($model, AdminInterface $admin = null)
    {
        if (null === $admin) {
            $admin = $this->pool->getAdminByClass(ClassUtils::getClass($model));
        }

        return $admin->getUrlsafeIdentifier($model);
    }

    /**
     * @param string[] $xEditableTypeMapping
     */
    public function setXEditableTypeMapping($xEditableTypeMapping)
    {
        $this->xEditableTypeMapping = $xEditableTypeMapping;
    }

    /**
     * @return string|bool
     */
    public function getXEditableType($type)
    {
        return isset($this->xEditableTypeMapping[$type]) ? $this->xEditableTypeMapping[$type] : false;
    }

    /**
     * Return xEditable choices based on the field description choices options & catalogue options.
     * With the following choice options:
     *     ['Status1' => 'Alias1', 'Status2' => 'Alias2']
     * The method will return:
     *     [['value' => 'Status1', 'text' => 'Alias1'], ['value' => 'Status2', 'text' => 'Alias2']].
     *
     * @return array
     */
    public function getXEditableChoices(FieldDescriptionInterface $fieldDescription)
    {
        $choices = $fieldDescription->getOption('choices', []);
        $catalogue = $fieldDescription->getOption('catalogue');
        $xEditableChoices = [];
        if (!empty($choices)) {
            reset($choices);
            $first = current($choices);
            // the choices are already in the right format
            if (is_array($first) && array_key_exists('value', $first) && array_key_exists('text', $first)) {
                $xEditableChoices = $choices;
            } else {
                foreach ($choices as $value => $text) {
                    if ($catalogue) {
                        if (null !== $this->translator) {
                            $text = $this->translator->trans($text, [], $catalogue);
                            // NEXT_MAJOR: Remove this check
                        } elseif (method_exists($fieldDescription->getAdmin(), 'trans')) {
                            $text = $fieldDescription->getAdmin()->trans($text, [], $catalogue);
                        }
                    }

                    $xEditableChoices[] = [
                        'value' => $value,
                        'text' => $text,
                    ];
                }
            }
        }

        return $xEditableChoices;
    }

    /**
     * Get template.
     *
     * @param string $defaultTemplate
     *
     * @return \Twig_TemplateInterface
     */
    protected function getTemplate(
        FieldDescriptionInterface $fieldDescription,
        $defaultTemplate,
        Environment $environment
    ) {
        $templateName = $fieldDescription->getTemplate() ?: $defaultTemplate;

        try {
            $template = $environment->loadTemplate($templateName);
        } catch (LoaderError $e) {
            @trigger_error(
                'Relying on default template loading on field template loading exception '.
                'is deprecated since 3.1 and will be removed in 4.0. '.
                'A \Twig_Error_Loader exception will be thrown instead',
                E_USER_DEPRECATED
            );
            $template = $environment->loadTemplate($defaultTemplate);

            if (null !== $this->logger) {
                $this->logger->warning(sprintf(
                    'An error occured trying to load the template "%s" for the field "%s", '.
                    'the default template "%s" was used instead.',
                    $templateName,
                    $fieldDescription->getFieldName(),
                    $defaultTemplate
                ), ['exception' => $e]);
            }
        }

        return $template;
    }
}
