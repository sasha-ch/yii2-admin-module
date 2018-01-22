<?php

namespace asdfstudio\admin\base;

use asdfstudio\admin\forms\widgets\Input;
use asdfstudio\admin\forms\widgets\Button;
use asdfstudio\admin\forms\widgets\Select;
use asdfstudio\admin\forms\widgets\Textarea;
use asdfstudio\admin\forms\Form;
use yii\base\Widget;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\helpers\Inflector;
use ReflectionClass;

/**
 * Class Entity
 *
 * @package asdfstudio\admin
 */
abstract class Entity extends Component
{
    /**
     * Triggers after new model creation
     */
    const EVENT_CREATE_SUCCESS = 'entity_create_success';
    const EVENT_CREATE_FAIL = 'entity_create_fail';
    /**
     * Trigers after model updated
     */
    const EVENT_UPDATE_SUCCESS = 'entity_update_success';
    const EVENT_UPDATE_FAIL = 'entity_update_fail';
    /**
     * Triggers after model deleted
     */
    const EVENT_DELETE_SUCCESS = 'entity_delete_success';
    const EVENT_DELETE_FAIL = 'entity_delete_fail';

    /**
     * @var string Entity Id
     */
    public $id;
    /**
     * @var string Model's class
     */
    public $modelClass;
    /**
     * @var array Labels
     */
    public $labels;
    /**
     * @var array Attributes
     */
    public $attributes;

    protected $formatMap = [
        'html' => '\asdfstudio\admin\forms\widgets\Textarea',
        'model' => '\asdfstudio\admin\forms\widgets\Select',
    ];

    /**
     * List of model's attributes for displaying table and view and edit pages configuration
     *
     * ```php
     *  [ // display attributes. @see [[DetailView]] for configuration syntax
     *      'id',
     *      'username',
     *      'bio:html',
     *      'dob:date',
     *      [ // support related models
     *          'attribute' => 'posts', // getter name, e.g. getPosts()
     *          'format' => ['model', ['labelAttribute' => 'title']], // @see [[AdminFormatter]]
     *          'visible' => true, // visible item in list, view, create and update
     *          'editable' => false, // edit item in update and create
     *      ],
     *  ],
     * ```
     *
     * @return array
     */
    public static function attributes()
    {
        return [];
    }

    /**
     * Should return an array with single and plural form of model name, e.g.
     *
     * ```php
     *  return ['User', 'Users'];
     * ```
     *
     * @return array
     */
    public static function labels()
    {

        $class = static::getShortName(static::model());

        return [$class, Inflector::pluralize($class)];
    }

    protected function getShortName($class)
    {
        $reflect = new ReflectionClass($class);

        return $reflect->getShortName();
    }

    /**
     * Slug for url, e.g.
     * Slug should match regex: [\w\d-_]+
     *
     * ```php
     *  return 'user'; // url will be /admin/manage/user[<id>[/<action]]
     * ```
     *
     * @return string
     */
    public static function slug()
    {
        return Inflector::slug(static::getShortName(static::model()));
    }

    /**
     * Model's class name
     *
     * ```php
     *  return vendorname\blog\Post::className();
     * ```
     *
     * @return string
     * @throws InvalidConfigException
     */
    public static function model()
    {
        throw new InvalidConfigException('Entity must have model name');
    }

    /**
     * Class name of form using for update or create operation
     * Default form class is `asdfstudio\admin\base\Form`
     * For configuration syntax see [[[Form]]
     *
     * ```php
     *  return [
     *      'class' => vendorname\blog\forms\PostForm::className(),
     *      'fields' => [
     *          ...
     *      ]
     *  ];
     * ```
     *
     * @return array
     */
    public function form()
    {
        return [
            'class' => Form::className(), // form class name
            'renderSaveButton' => true, // render save button or not
            'fields' => [
                'wrapper' => '<div class="col-md-8">{items}</div>', // wrapper of items
                'items' => $this->items(),
            ],
        ];
    }

    protected function items($scenario = Model::SCENARIO_DEFAULT)
    {
        $items = [];
        foreach ($this->attributes as $attribute) {
            $f = $attribute['format'];
            if (is_array($f)) {
                list($format, $options) = $f;
            } else {
                $format = $f;
            }
            $class = array_key_exists($format, $this->formatMap) ? $this->formatMap[$format] : Input::className();
            $item = [
                'class' => $class,
                'attribute' => $attribute['attribute'],
                'type' => $format
            ];

            if ($format == 'model') {
                $modelClass = static::model();
                $getter = 'get'.ucfirst($attribute['attribute']);
                $attrQuery = (new $modelClass)->$getter();
                $attrModelClass = $attrQuery->modelClass;
                $item += [
                    'labelAttribute' => $options['labelAttribute'],
                    'query' => $attrModelClass::find()->indexBy('id'),
                    'multiple' => $attrQuery->multiple,
                ];
            }
            $items[] = $item;
        }

        return $items;

        /*[ // fields configuration
           [

               'items' => [
                   [
                       'class' => Input::className(),
                       'attribute' => 'username',
                   ],
                   [
                       'class' => Select::className(),
                       'attribute' => 'role',
                       'items' => [User::ROLE_USER => 'User', User::ROLE_ADMIN => 'Admin'],
                   ],
                   [ // list of all user posts
                       'class' => Select::className(),
                       'attribute' => 'posts', // attribute name, for saving should implement setter for `posts` attribute
                       'labelAttribute' => 'title', // shows in select box
                       'query' => Post::find()->indexBy('id'), // all posts, should be indexed
                   ],
               ]
           ],
           [
               'wrapper' => '<div class="col-md-4">{items}</div>',
               'items' => [
                   [ // example button
                       'id' => 'ban',
                       'class' => Button::className(),
                       'label' => 'Ban user',
                       'options' => [
                           'class' => 'btn btn-danger'
                       ],
                       'action' => function(User $model) {
                           $model->setAttribute('status', User::STATUS_BANNED);
                           return true;
                       },
                   ],
               ],
           ],

   ];*/
    }
}
