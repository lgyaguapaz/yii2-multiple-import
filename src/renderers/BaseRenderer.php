<?php

/**
 * @link https://github.com/unclead/yii2-multiple-input
 * @copyright Copyright (c) 2014 unclead
 * @license https://github.com/unclead/yii2-multiple-input/blob/master/LICENSE.md
 */

namespace unclead\multipleinput\renderers;

use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\base\NotSupportedException;
use yii\base\Object;
use yii\db\ActiveRecordInterface;
use yii\web\View;
use yii\widgets\ActiveForm;
use unclead\multipleinput\MultipleInput;
use unclead\multipleinput\TabularInput;
use unclead\multipleinput\assets\MultipleInputAsset;
use unclead\multipleinput\assets\MultipleInputSortableAsset;
use unclead\multipleinput\components\BaseColumn;

/**
 * Class BaseRenderer
 * @package unclead\multipleinput\renderers
 */
abstract class BaseRenderer extends Object implements RendererInterface
{
    /**
     * @var string the ID of the widget
     */
    public $id;

    /**
     * @var ActiveRecordInterface[]|Model[]|array input data
     */
    public $data = null;

    /**
     * @var BaseColumn[] array of columns
     */
    public $columns = [];

    /**
     * @var int maximum number of rows
     */
    public $max;

    /**
     * @var int minimum number of rows.
     * @since 1.2.6 Use this option with value 0 instead of `allowEmptyList` with `true` value
     */
    public $min;

    /**
     * @var array client-side attribute options, e.g. enableAjaxValidation. You may use this property in case when
     * you use widget without a model, since in this case widget is not able to detect client-side options
     * automatically.
     */
    public $attributeOptions = [];

    /**
     * @var array the HTML options for the `remove` button
     */
    public $removeButtonOptions = [];

    /**
     * @var array the HTML options for the `add` button
     */
    public $addButtonOptions = [];

    /**
     * @var bool whether to allow the empty list
     */
    public $allowEmptyList = false;

    /**
     * @var array|\Closure the HTML attributes for the table body rows. This can be either an array
     * specifying the common HTML attributes for all body rows, or an anonymous function that
     * returns an array of the HTML attributes. It should have the following signature:
     *
     * ```php
     * function ($model, $index, $context)
     * ```
     *
     * - `$model`: the current data model being rendered
     * - `$index`: the zero-based index of the data model in the model array
     * - `$context`: the widget object
     *
     */
    public $rowOptions = [];

    /**
     * @var string
     */
    public $columnClass;

    /**
     * @var string position of add button. By default button is rendered in the row.
     */
    public $addButtonPosition = self::POS_ROW;

    /**
     * @var TabularInput|MultipleInput
     */
    protected $context;

    /**
     * @var string
     */
    private $indexPlaceholder;

    /**
     * @var ActiveForm the instance of `ActiveForm` class.
     */
    public $form;

    /**
     * @var bool allow sorting.
     * @internal this property is used when need to allow sorting rows.
     */
    public $sortable = false;

    /**
     * @var bool whether to render inline error for all input. Default to `false`. Can be override in `columns`
     * @since 2.10
     */
    public $enableError = false;

    /**
     * @inheritdoc
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    public function init()
    {
        parent::init();

        $this->prepareMinOption();
        $this->prepareMaxOption();
        $this->prepareColumnClass();
        $this->prepareButtons();
        $this->prepareIndexPlaceholder();
    }

    private function prepareColumnClass()
    {
        if (!$this->columnClass) {
            throw new InvalidConfigException('You must specify "columnClass"');
        }

        if (!class_exists($this->columnClass)) {
            throw new InvalidConfigException('Column class "' . $this->columnClass. '" does not exist');
        }
    }

    private function prepareMinOption()
    {
        // Set value of min option based on value of allowEmptyList for BC
        if ($this->min === null) {
            $this->min = $this->allowEmptyList ? 0 : 1;
        } else {
            if ($this->min < 0) {
                throw new InvalidConfigException('Option "min" cannot be less 0');
            }

            // Allow empty list in case when minimum number of rows equal 0.
            if ($this->min === 0 && !$this->allowEmptyList) {
                $this->allowEmptyList = true;
            }

            // Deny empty list in case when min number of rows greater then 0
            if ($this->min > 0 && $this->allowEmptyList) {
                $this->allowEmptyList = false;
            }
        }
    }

    private function prepareMaxOption()
    {
        if ($this->max === null) {
            $this->max = PHP_INT_MAX;
        }

        if ($this->max < 1) {
            $this->max = 1;
        }

        // Maximum number of rows cannot be less then minimum number.
        if ($this->max < $this->min) {
            $this->max = $this->min;
        }
    }

    private function prepareButtons()
    {
        if ($this->addButtonPosition === null || $this->addButtonPosition === []) {
            $this->addButtonPosition = $this->min === 0 ? self::POS_HEADER : self::POS_ROW;
        }
        if (!is_array($this->addButtonPosition)) {
            $this->addButtonPosition = (array) $this->addButtonPosition;
        }

        if (!array_key_exists('class', $this->removeButtonOptions)) {
            $this->removeButtonOptions['class'] = 'btn btn-danger';
        }

        if (!array_key_exists('label', $this->removeButtonOptions)) {
            $this->removeButtonOptions['label'] = Html::tag('i', null, ['class' => 'glyphicon glyphicon-remove']);
        }

        if (!array_key_exists('class', $this->addButtonOptions)) {
            $this->addButtonOptions['class'] = 'btn btn-default';
        }

        if (!array_key_exists('label', $this->addButtonOptions)) {
            $this->addButtonOptions['label'] = Html::tag('i', null, ['class' => 'glyphicon glyphicon-plus']);
        }
    }


    /**
     * Creates column objects and initializes them.
     *
     * @throws \yii\base\InvalidConfigException
     */
    protected function initColumns()
    {
        foreach ($this->columns as $i => $column) {
            $definition = array_merge([
                'class'     => $this->columnClass,
                'renderer'  => $this,
                'context'   => $this->context
            ], $column);

            if (!is_array($this->addButtonOptions)) {
                $this->addButtonOptions = [$this->addButtonOptions];
            }

            if (!array_key_exists('attributeOptions', $definition)) {
                $definition['attributeOptions'] = $this->attributeOptions;
            }

            if (!array_key_exists('enableError', $definition)) {
                $definition['enableError'] = $this->enableError;
            }

            $this->columns[$i] = Yii::createObject($definition);
        }
    }

    public function render()
    {
        $this->initColumns();

        $view = $this->context->getView();
        MultipleInputAsset::register($view);

        // Collect all js scripts which were added before rendering of our widget
        $jsBefore= [];
        if (is_array($view->js)) {
            foreach ($view->js as $position => $scripts) {
                foreach ($scripts as $key => $js) {
                    if (!isset($jsBefore[$position])) {
                        $jsBefore[$position] = [];
                    }
                    $jsBefore[$position][$key] = $js;
                }
            }
        }

        $content  = $this->internalRender();

        // Collect all js scripts which has to be appended to page before initialization widget
        $jsInit = [];
        if (is_array($view->js)) {
            foreach ($view->js as $position => $scripts) {
                foreach ($scripts as $key => $js) {
                    if (isset($jsBefore[$position][$key])) {
                        continue;
                    }
                    $jsInit[$key] = $js;
                    $jsBefore[$position][$key] = $js;
                    unset($view->js[$position][$key]);
                }
            }
        }

        $template = $this->prepareTemplate();

        $jsTemplates = [];
        if (is_array($view->js) && isset($view->js[View::POS_READY])) {
            foreach ($view->js[View::POS_READY] as $key => $js) {
                if (isset($jsBefore[View::POS_READY][$key])) {
                    continue;
                }

                $jsTemplates[$key] = $js;
                unset($view->js[View::POS_READY][$key]);
            }
        }

        $options = Json::encode([
            'id'                => $this->id,
            'inputId'           => $this->context->options['id'],
            'template'          => $template,
            'jsInit'            => $jsInit,
            'jsTemplates'       => $jsTemplates,
            'max'               => $this->max,
            'min'               => $this->min,
            'attributes'        => $this->prepareJsAttributes(),
            'indexPlaceholder'  => $this->getIndexPlaceholder()
        ]);

        $js = "jQuery('#{$this->id}').multipleInput($options);";

        if($this->sortable) {
            MultipleInputSortableAsset::register($view);
            $js .= "$('#{$this->id} table').sorting({containerSelector: 'table', itemPath: '> tbody', itemSelector: 'tr', placeholder: '<tr class=\"placeholder\"/>', handle:'.drag-handle'});";
        }

        $view->registerJs($js);

        return $content;
    }

    /**
     * @return mixed
     * @throws NotSupportedException
     */
    abstract protected function internalRender();

    /**
     * @return string
     */
    abstract protected function prepareTemplate();

    /**
     * @return mixed
     */
    public function getIndexPlaceholder()
    {
        return $this->indexPlaceholder;
    }

    /**
     * @return bool
     */
    protected function isAddButtonPositionHeader()
    {
        return in_array(self::POS_HEADER, $this->addButtonPosition);
    }

    /**
     * @return bool
     */
    protected function isAddButtonPositionFooter()
    {
        return in_array(self::POS_FOOTER, $this->addButtonPosition);
    }

    /**
     * @return bool
     */
    protected function isAddButtonPositionRow()
    {
        return in_array(self::POS_ROW, $this->addButtonPosition);
    }

    /**
     * @return bool
     */
    protected function isAddButtonPositionRowBegin()
    {
        return in_array(self::POS_ROW_BEGIN, $this->addButtonPosition);
    }

    private function prepareIndexPlaceholder()
    {
        $this->indexPlaceholder = 'multiple_index_' . $this->id;
    }

    /**
     * Prepares attributes options for client side.
     *
     * @return array
     */
    protected function prepareJsAttributes()
    {
        $attributes = [];
        foreach ($this->columns as $column) {
            $model = $column->getModel();
            $inputID = str_replace(['-0', '-0-'], '', $column->getElementId(0));
            if ($this->form instanceof ActiveForm && $model instanceof Model) {
                $field = $this->form->field($model, $column->name);
                foreach ($column->attributeOptions as $name => $value) {
                    if ($field->hasProperty($name)) {
                        $field->$name = $value;
                    }
                }
                $field->render('');
                $attributeOptions = array_pop($this->form->attributes);
                if (isset($attributeOptions['name']) && $attributeOptions['name'] === $column->name) {
                    $attributes[$inputID] = ArrayHelper::merge($attributeOptions, $column->attributeOptions);
                } else {
                    array_push($this->form->attributes, $attributeOptions);
                }
            } else {
                $attributes[$inputID] = $column->attributeOptions;
            }
        }

        return $attributes;
    }
}
