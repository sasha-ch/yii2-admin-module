<?php

namespace asdfstudio\admin\forms;

use asdfstudio\admin\forms\widgets\Button;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\bootstrap\ActiveForm;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use Yii;


/**
 * Class Form
 *
 * @package asdfstudio\admin\forms
 *
 * Renders form with defined fields and layout.
 *
 * ```php
 * // example: two columns form
 *  echo Form::widget([
 *      'fields' => [ // first column
 *          'wrapper' => '<div class="col-md-8">{items}</div>',
 *          'items' => [
 *              [
 *                  'class' => ActiveField::className(), // field widget name
 *                  'attribute' => 'title', // model's attribute name
 *              ],
 *              [
 *                  'class' => DropdownField::className(), // field widget name
 *                  'attribute' => 'author', // model's attribute name
 *                  'query' => User::find()->indexBy('id'), // fill list of possible autohrs
 *              ],
 *              [
 *                  'class' => HtmlField::className(), // field widget name
 *                  'attribute' => 'content', // model's attribute name
 *              ],
 *          ],
 *      ],
 *      [ // second column
 *          'wrapper' => '<div class="col-md-4">{items}</div>',
 *          'items' => [
 *              [
 *                  'class' => DropdownField::className(), // field widget name
 *                  'attribute' => 'tags', // model's attribute name
 *              ],
 *              [
 *                  'id' => 'publish', // id is required for binding and executing action
 *                  'class' => Button::className(), // renders button, instead of input field,
 *                  'label' => 'Publish',
 *                  'action' => function($model) { // can be callable or string
 *                      return $model->publish();
 *                  },
 *              ],
 *          ],
 *      ],
 *  ]);
 *
 * ```
 */
class Form extends ActiveForm
{
    /**
     * Model used in form
     *
     * @var ActiveRecord
     */
    public $model;

    protected $linkedFields = [];

    /**
     * Fields list
     *
     * @var array
     */
    public $fields = [];

    /**
     * If true, "Save" button will be rendered at end of form
     *
     * @var bool
     */
    public $renderSaveButton = true;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        if ($this->renderSaveButton) {
            $saveButton = [
                'id' => 'save',
                'class' => Button::className(),
                'label' => Yii::t('admin', 'Save'),
                'options' => [
                    'class' => 'btn btn-success',
                ],
            ];
            /*$this->fields[] = [
                'wrapper' => '<div class="form-group">{items}</div>',
                'items' => [
                    $saveButton
                ]
            ];*/
            $this->fields['items'][] = $saveButton;
        }
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        echo $this->renderForm($this->fields);
        parent::run();
    }

    /**
     * Renders form with fields
     *
     * @param array $fields
     *
     * @return string
     * @throws InvalidConfigException
     */
    public function renderForm($fields)
    {

        if (isset($fields['visible']) && !$fields['visible']) {
            return '';
        }
        if (!is_array($fields)) {
            throw new InvalidConfigException('Parameter "fields" must be an array');
        } elseif (isset($fields['class'])) {
            if (is_a($fields['class'], Button::className(), true)) {
                return Button::widget(ArrayHelper::merge([
                    'tagName' => 'input',
                    'options' => [
                        'name' => $fields['id'],
                        'type' => 'submit',
                        'value' => $fields['label'],
                    ],
                ], $fields));
            } else {
                if (!isset($fields['attribute'])) {
                    throw new InvalidConfigException('Layout\'s field config must have "attribute" property');
                }

                return $this->field($this->model, $fields['attribute'])->widget($fields['class'], $fields);
            }
        } elseif (isset($fields['items'])) {
            $items = $this->renderForm($fields['items']);
            if (isset($fields['wrapper'])) {
                $items = strtr($fields['wrapper'], ['{items}' => $items]);
            }

            return $items;
        } else {
            $out = '';
            foreach ($fields as $field) {
                if (is_array($field)) {
                    $out .= $this->renderForm($field);
                }
            }

            return $out;
        }
    }

    /**
     * Return registered actions list indexed by name
     *
     * @param array $actions
     *
     * @return array
     */
    public function getActions($actions = null)
    {
        if ($actions === null) {
            $actions = $this->fields;
        }
        if (is_array($actions)) {
            $result = [];
            if (isset($actions['action']) && isset($actions['id']) && is_a($actions['class'], Button::className(),
                    true)) {
                $result[$actions['id']] = $actions['action'];
            } else {
                $res = [];
                foreach ($actions as $action) {
                    $res = ArrayHelper::merge($res, $this->getActions($action));
                }
                $result = ArrayHelper::merge($result, $res);
            }

            return $result;
        }

        return [];
    }

    /**
     * @throws \yii\base\InvalidCallException
     */
    public function runActions()
    {
        $data = Yii::$app->getRequest()->getBodyParams();
        $actions = $this->getActions();

        foreach ($actions as $action => $closure) {
            if (isset($data[$action])) {
                if (is_callable($closure)) {
                    call_user_func($closure, $this->model);
                } elseif (is_string($closure)) {
                    call_user_func([$this->model, $closure]);
                } else {
                    throw new InvalidCallException(sprintf('Method "%s" not found', $closure));
                }
            }
        }
    }

    public function saveModel()
    {
        $model = $this->model;

        return $model::getDb()->transaction(function ($db) use ($model){

            $modelItems = $this->getLinkedFields();

            foreach ($modelItems as $item) {
                $this->saveLinkedModel($model, $item);
            }
            return $model->save();
        });

    }

    protected function getLinkedFields($namesOnly = false)
    {
        $fields = array_filter($this->fields['items'], function ($v){
            //get items/attributes list with 'model' type/format
            return isset($v['type']) && ($v['type'] == 'model');
        });

        return $namesOnly ? array_column($fields, 'attribute') : $fields;
    }

    protected function saveLinkedModel($model, $item)
    {
        $attribute = $item['attribute'];
        $modelClass = $item['query']->modelClass;
        $multiple = $item['multiple'];

        if ($multiple == true) {
            $oldAttrIds = [];
            foreach ($model->{$attribute} as $oldVal) {
                $oldAttrIds[] = $oldVal->id;
            }
            $newAttrIds = (array)$this->linkedFields[$attribute];

            $toLink = array_filter(array_diff($newAttrIds, $oldAttrIds));
            $toUnlink = array_filter(array_diff($oldAttrIds, $newAttrIds));

            foreach ($toLink as $id) {
                $linkedModel = $modelClass::findOne($id);
                $model->link($attribute, $linkedModel);
            }
            foreach ($toUnlink as $id) {
                $linkedModel = $modelClass::findOne($id);
                $model->unlink($attribute, $linkedModel, true);
            }
        } else {
            $val = $this->linkedFields[$attribute];
            if (empty($val) && empty($model->{$attribute}) ||
                is_object($model->{$attribute}) && $val == $model->{$attribute}->id) {
                return true;
            }

            if (!empty($val)) {
                $linkedModel = $modelClass::findOne($val);
                $model->link($attribute, $linkedModel);
            } else {
                $linkedModel = $model->{$attribute};
                $model->unlink($attribute, $linkedModel);
            }

        }

        return true;
    }

    public function load($data, $formName = null)
    {
        //store linked models data
        $this->loadLinked($data, $formName);

        //load normal AR attrs
        return $this->model->load($data, $formName);
    }

    protected function loadLinked($data, $formName = null)
    {
        $scope = $formName === null ? $this->model->formName() : $formName;
        if ($scope != '' && isset($data[$scope])) {
            $data = $data[$scope];
        }
        $linkedFields = $this->getLinkedFields(true);
        foreach ($linkedFields as $field) {
            $this->linkedFields[$field] = $data[$field];
        }
    }

}
