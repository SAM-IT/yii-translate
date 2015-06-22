<?php
namespace SamIT\Yii1\Behaviors;

use SamIT\Yii1\Models\Translation;

/**
 * TranslatableBehavior
 * Automatically adds relation for translatable fields.
 *
 * Author: Sam Mousa <sam@mousa.nl>
 * Version: 1.0
 */

class TranslatableBehavior  extends \CActiveRecordBehavior
{
	public $translationModel = \SamIT\Yii1\Models\Translation::class;
    /**
     * Save translations when saving base model.
     * @var boolean
     */
    public $autoSave = true;
    
    /**
     * The attributes that are translatable.
     * @var array
     */
    public $attributes = [];

    /**
     * This setting allows for overriding the model name when looking for translations.
     * For single table inheritance for example it might make sense to set this to the base model;
     * this will allow reusing the translations if the type of the model changes.
     * @var string Override the model name, if not set get_class will be used.
     */
    public $model;
    /**
     * The base language for the model.
     * Defaults to \Yii::app()->sourceLanguage.
     */
    protected $_baseLanguage;
    
    /**
     *
     * @var type 
     */
    protected $_language;
    
    public $languages = [];
    /**
     *
     * @var \SamIT\Yii1\Models\Translation
     */
    protected $_current;
    
    protected $_originalValues = [];

    /**
     * Contains the translations that need to be update after save.
     * @var Callable[]
     */
    protected $updates = [] ;

    public function afterFind($event) {
        $this->backupValues();
	}
    
    public function afterSave($event) {
        parent::afterSave($event);
        // Save all languages updated via setTranslatedFields().
        if ($this->autoSave) {
            foreach($this->updates as $language => $callback) {
                $callback[0]->model_id = $this->owner->id;

                if (!call_user_func($callback)) {
                    throw new \Exception("Model callback failed.");
                }
            }
            if (isset($this->_current)) {
                $this->_current->save();
            }
        }

        $this->backupValues();
        if (isset($this->_current)) {
            $this->setLanguage($this->_current->language);
        }

    }
	
    public function attach($owner) {
        parent::attach($owner);
        $this->owner->metaData->addRelation('translations', [
            \CActiveRecord::HAS_MANY, 
            $this->translationModel,
            'model_id', 'on' => "model = :class", 'params' => [':class' => $this->getModel()], 'index' => 'language'
        ]);
        $this->owner->getValidatorList()->add(
            \CValidator::createValidator('safe', $this->owner, 'translatedFields')
        );
    }
    
    protected function backupValues() {
        foreach($this->attributes as $attribute) {
            $this->_originalValues[$attribute] = $this->owner->$attribute;
        }
    }
   
    protected function getBaseLanguage() {
        if (isset($this->_baseLanguage) && is_callable($this->_baseLanguage)) {
            $result = $this->_baseLanguage($this->owner);
        } elseif (isset($this->_baseLanguage)) {
            $result = $this->_baseLanguage;
        } else {
            $result = \Yii::app()->sourceLanguage;
        }
        return $result;
    }

    protected function setBaseLanguage($value) {
        $this->_baseLanguage = $value;
    }

    public function afterDelete($event) {
        $class = $this->translationModel;
        $class::model()->deleteAllByAttributes([
            'model' => $this->getModel(),
            'model_id' => $this->owner->primaryKey
        ]);
    }
    public function beforeSave($event)
	{


        // If the language is the same as the base language no need to auto save.
        if ($this->language != $this->baseLanguage) {
            // Autosave, changed language.
            if ($this->autoSave) {
                foreach ($this->attributes as $attribute) {
                    $this->_current->$attribute = $this->owner->$attribute;
                }
            }
            $this->restoreValues();
        }
        return true;
	}
    
    public function detach($owner) {
        $this->owner->metaData->removeRelation('translations');
        parent::detach($owner);
    }
    
    public function getLanguage() {
        return isset($this->_current) ? $this->_current->language : $this->baseLanguage;
    }
    
    
    protected function restoreValues() {
        // Restore attributes to original values.
        foreach($this->_originalValues as $attribute => $value) {
            $this->owner->$attribute = $value;
        }
    }

    public function getModel() {
        return isset($this->model) ? $this->model : get_class($this->owner);
    }

    /**
     * Adds a new language, it is added to the related records but not saved.
     * It is also not made the current language.
     * @param string $language
     */
    protected function addLanguage($language)
    {
        $result = new $this->translationModel;
        $result->model = $this->getModel();
        $result->model_id = $this->owner->getPrimaryKey();
        $result->language = $language;
        $translations =  $this->owner->translations;
        $translations[$language] = $result;
        $this->owner->translations = $translations;
        return $result;
    }
    public function setLanguage($language) {
        // Language is not the source language, load translation model.
        if ($language != $this->getBaseLanguage()) {
            $translations = $this->owner->getRelated('translations');
            if (isset($translations[$language])) {
                $this->_current = $translations[$language];
            } else {
                $this->_current = $this->addLanguage($language);
                // Restore values so we create our new language based on the base language.
                $this->restoreValues();
                foreach ($this->attributes as $attribute) {
                    $this->_current->$attribute = $this->owner->$attribute;
                }
            }
            
            if(isset($this->_current)) {
                foreach ($this->attributes as $attribute) {
                    if (isset($this->_current->$attribute)) {
                        $this->owner->$attribute = $this->_current->$attribute;
                    }
                }
            }
        } else {
            $this->restoreValues();
            $this->_current = null;
        }
    }

    public function getTranslatableAttributes() {
        return $this->attributes;
    }

    /**
     * Add support for mass setting translated field(s) via
     * $model->translatedFields
     * @param string[language][field] $value
     */
    public function setTranslatedFields($value) {
        // Get base language first.
        if (isset($value[$this->getBaseLanguage()])) {
            foreach ($value[$this->getBaseLanguage()] as $field => $translated) {
                $this->owner->$field = $translated;
            }
            unset($value[$this->getBaseLanguage()]);
            // Copy values from owner.
            $this->backupValues();

        }

        foreach($value as $language => $values) {
            if (isset($this->owner->translations[$language])) {
                $model = $this->owner->translations[$language];
            } else {
                $model = $this->addLanguage($language);
            }


            // We filter the values so we don't save empty translations.
            $values = array_filter($values);
            // Check if the translations are the same as the base language, if so don't save them.
            foreach($values as $key => $translation) {
                // Skip if the translation is equal to the base languages' original value.
                if ($this->_originalValues[$key] == $translation) {
                    unset($values[$key]);
                // Or if the translation is equal to the base languages' new value.
                } elseif ($translation == $this->owner->$key) {
                    unset($values[$key]);
                }
            }

            if (empty($values)) {
                // Remove translation since its values have been emptied.
                if (!$model->isNewRecord) {
                    $this->updates[$language] = [$model, 'delete'];
                }
            } else {

                foreach ($values as $field => $translated) {
                    $model->$field = $translated;
                }
                $this->updates[$language] = [$model, 'save'];
                /**
                 * Not ideal since will execute 1 query for each language.
                 */
//                if (!$model->save()) {
//                    throw new \Exception(print_r($model->getErrors(), true));
//                }
            }

        }
    }

    public function getTranslatedFields() {
        $base = [];
        foreach($this->attributes as $attribute) {
            $base[$attribute] = $this->owner->$attribute;
        }

        $result = [$this->getBaseLanguage() => $base];
        foreach($this->owner->translations as $language => $translation) {
            $result[$language] = $translation->dataStore;
        }

        return $result;
    }

}
