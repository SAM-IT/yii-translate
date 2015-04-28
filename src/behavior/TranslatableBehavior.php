<?php
namespace SamIT\Yii1\Behaviors;
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
    
    
    public function afterFind($event) {
        $this->backupValues();
	}
    
    public function afterSave($event) {
        parent::afterSave($event);
        $this->backupValues();
        if (isset($this->_current)) {
            $this->setLanguage($this->_current->language);
        }
    }
	
    public function attach($owner) {
        parent::attach($owner);
        $class = get_class($this->owner);
        $this->owner->metaData->addRelation('translations', [
            \CActiveRecord::HAS_MANY, 
            $this->translationModel,
            'model_id', 'on' => "model = :class", 'params' => [':class' => $class], 'index' => 'language'
        ]);
    }
    
    protected function backupValues() {
        foreach($this->attributes as $attribute) {
            $this->_originalValues[$attribute] = $this->owner->$attribute;
        }
    }
   
    protected function getBaseLanguage() {
        return isset($this->_baseLanguage) ? $this->_baseLanguage : \Yii::app()->sourceLanguage;
    }

    protected function setBaseLanguage($value) {
        $this->_baseLanguage = $value;
    }
    public function beforeSave($event)
	{
        if ($this->language == $this->baseLanguage) {
            return true;
        }
        // Autosave, changed language.
        if ($this->autoSave) {
            foreach($this->attributes as $attribute) {
                $this->_current->$attribute = $this->owner->$attribute;
            }
            if (!$this->_current->save()) {
                return false;
            }
        }
        $this->restoreValues();
        return true;
	}
    
    public function detach($owner) {
        $this->owner->metaData->removeRelation('translations');
        parent::detach($owner);
    }
    
    public function getLanguage() {
        return isset($this->_current) ? $this->_current->language : $this->baseLanguage
    }
    
    
    protected function restoreValues() {
        // Restore attributes to original values.
        foreach($this->_originalValues as $attribute => $value) {
            $this->owner->$attribute = $value;
        }

    }
    public function setLanguage($language = null) {
        // Language is not the source language, load translation model.
        if ($language != \Yii::app()->sourceLanguage) {
            $translations = $this->owner->getRelated('translations');
            if (isset($translations[$language])) {
                $this->_current = $translations[$language];
            } else {
                $this->_current = new \Befound\ActiveRecord\Translation();
                $this->_current->model = get_class($this->owner);
                $this->_current->model_id = $this->owner->getPrimaryKey();
                $this->_current->language = $language;
                $this->restoreValues();
                foreach ($this->attributes as $attribute) {
                    $this->_current->$attribute = $this->owner->$attribute;
                }
            }
            
            if(isset($this->_current)) {
                foreach ($this->attributes as $attribute) {
                    $this->owner->$attribute = $this->_current->$attribute;
                }
            }
        } else {
            $this->restoreValues();
            $this->_current = null;
        }
    }


}
