<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\CategoryModel;
use App\Entity\Color;
use App\Entity\ColorImage;
use App\Entity\File;
use App\Entity\Language;
use App\Entity\Model;
use App\Entity\ModelImage;
use App\Entity\Product;
use App\Entity\ProductImage;
use App\Entity\Project;
use App\Entity\SystemType;
use App\Entity\Translation;
use App\Entity\Type;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;
use Spatie\ImageOptimizer\OptimizerChain;
use Spatie\ImageOptimizer\Optimizers\Jpegoptim;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ProductsImportService
{
    private const DEBUG_LOG = true;

    private ContainerInterface $container;
    private PlainQueryService $plainQueryService;
    private OptimizerChain $optimizer;
    private ParameterBagInterface $params;
    private $project;
    private $lastCategoryIndex = 0;
    private array $systemCategoriesNames = ['агентам'];
    private ArrayCollection $languages;
    private $colorImages;

    //Массив объектов
    private $categoriesObject = [];
    private $typesObject = [];
    private $productsObject = [];
    private $modelObject = [];
    private $colorObject = [];
    private $translationObject = [];
    private $photoHashArray = [];

    //Массивы для ответа b2b
    private $productsMatrix;
    private $categoriesMatrix;
    private $typesMatrix;
    private $modelsMatrix;

    //Массивы для первого прохода
    private $categoriesMatrixObjects = [];
    private $typesMatrixObjects = [];

    //Массивы импорта
    private $productsImport;
    private $categoriesImport;
    private $typesImport;
    private $em;
    private $counterCodeType;

    const DATE_FORMAT_SHORT = 'Y-m-d';
    const DATE_FORMAT_FULL = 'd.m.Y H:i:s';


    private $modelImageTypes;
    private $lastIdProduct;

    // Типы изображений моделей
    private $imageTypesSlugs = [
        'base_photo' => [
            'osnovnoe-foto'
        ],
        'base_photo_b2b' => [
            'osnovnoe-foto-b2b',
        ],
        'package_photo' => [
           'foto-upakovki-speredi',
           'foto-upakovki-szadi'
        ]
    ];
    private LoggerInterface $logger;

    private string $sessionId;

    public function __construct(
        ContainerInterface $container,
        EntityManagerInterface $em,
        PlainQueryService $plainQueryService,
        ParameterBagInterface $params,
        LoggerInterface $importLogger
    )
    {
        $this->sessionId = md5(time() . uniqid('', true));
        $this->container = $container;
        $this->em = $em;
        $this->plainQueryService = $plainQueryService;
        $this->params = $params;
        $this->optimizer = (new OptimizerChain)
            ->addOptimizer(new Jpegoptim([
                '--strip-all',
                '--all-progressive',
                '--max=70',
            ]));
        $this->modelImageTypes = $this->em->getRepository(SystemType::class)->findBy(['class' => 'ImageType']);
        $this->logger = $importLogger;
    }

    /**
     * @param $data
     *
     * @return array
     */
    public function execute($data) : array
    {
        $response = [];

        $startTimeToLog = microtime(true);
        $this->writeLog('conteb2b/import/start: time:'. $startTimeToLog);

        $this->getBeforeData();
        ini_set("memory_limit", -1);
        set_time_limit(3600);

        if (isset($data['types'])) {
            $this->writeLog('conteb2b/import/processing-types: time:'. (microtime(true) - $startTimeToLog));
            $this->typesImport = $data['types'];
            $this->getImportActionType();
            $response['types'] = $this->typesMatrix;
        }
        if (isset($data['categories'])) {
            $this->writeLog('conteb2b/import/processing-categories: time:'. (microtime(true) - $startTimeToLog));
            $this->categoriesImport = $this->keyIndexArray($data['categories']);
            $this->getImportActionCategory();
            $response['categories'] = $this->categoriesMatrix;
        }
        if (isset($data['products'])) {
            $this->writeLog('conteb2b/import/processing-products: time:'. (microtime(true) - $startTimeToLog));
            $this->productsImport = $data['products'];
            $this->getImportActionProduct();
            $response['products'] = $this->productsMatrix;
            $response['models'] = $this->modelsMatrix;
        }
        //последний элемент массива для выборки на b2b
        $response['last_id'] = $this->lastIdProduct;
        $this->writeDebugLog(json_encode($response));
        $this->writeLog('conteb2b/import/end: time:'. $startTimeToLog);
        return $response;
    }

    private function getImportActionType() {
        $this->typesMatrixObjects = $this->keyIndexObject($this->em->getRepository(Type::class)->findAll(), 'B2bId');

        foreach ($this->typesImport as $value) {
            if (isset($this->typesObject[$value['id']])) {
                continue;
            }
            /** @var Type $type */
            $type = $this->typesMatrixObjects[$value['id']] ?? $this->getObjectType($value);

            $type->setName($value['name']);
            $type->setB2bId($value['id']);
            $this->addTranslationsFields($type, $value['translation'], 'type', 'name');

            $this->em->persist($type);
            $this->typesObject[$value['id']] = $type;
            $this->typesMatrix[] = [
                'id' => $type->getB2bId(),
                'matrix_id' => $type->getId()
            ];
            if (isset($value['id'])) {
                $this->writeDebugLog('type id ' . $value['id']);
            }
        }
        $this->em->flush();
    }

    private function getType($id) {

            if (isset($this->typesObject[(int)$id])) {
                return $this->typesObject[(int)$id];
            }
            $type = $this->em->getRepository(Type::class)->findOneBy(['b2b_id' => $id]);

            if (!empty($type)) {
                $this->typesObject[$type->getB2bId()] = $type;
            }

        return $type;
    }

    private function getImportActionCategory()
    {
        $this->categoriesMatrixObjects = $this->keyIndexObject($this->em->getRepository(Category::class)->findAll(), 'B2bId');

        foreach ($this->categoriesImport as $value) {
            $this->getCategoryByTree($value);
            if (isset($value['id'])) {
                $this->writeDebugLog('category id ' . $value['id']);
            }
        }
        $this->em->flush();
    }

    /**
     * @throws \Exception
     */
    private function getImportActionProduct() {
        $allCount = count($this->productsImport);
        foreach ($this->productsImport as $i =>  $value) {
            try {
                $product = $this->getObjectProduct($value);
                $product->setSku($value['sku']);
                $product->setSkuMf($value['sku_mf'] ?? '');
                $product->setCount((int)$value['count']);
                $product->setCountInBox((int)$value['count_in_box']);
                $product->setCountInPackage((int)$value['count_in_package']);
                $product->setWeight($value['weight']);
                if ($type = $this->getType($value['type'])) {
                    $product->setType($type);
                }
                $product->setTypeCode($value['type_code']);
                $product->setNewFrom($this->getDateTime($value['new_period']['from'], self::DATE_FORMAT_SHORT));
                $product->setNewTo($this->getDateTime($value['new_period']['to'], 'Y-m-d'));
                $product->setPreorderFrom($this->getDateTime($value['preorder_period']['from'], self::DATE_FORMAT_SHORT));
                $product->setPreorderTo($this->getDateTime($value['preorder_period']['to'], self::DATE_FORMAT_SHORT));
                $product->setIsActive($value['is_active']);

                if ($createdAt = $this->getDateTime($value['created_at'], self::DATE_FORMAT_FULL)) {
                    $product->setCreatedAt($createdAt);
                }

                if ($deletedAt = $this->getDateTime($value['deleted_at'], self::DATE_FORMAT_FULL)) {
                    $product->setDeletedAt($deletedAt);
                }

                if ($color = $this->getColor($value['color'])) {
                    $product->setColor($color);
                }

                $product->setBarcode($value['barcode']);
                $product->setSize($this->getDefaultNameLanguageTranslations($value['size']['translations']));
                $product->setSizeCode($value['size']['code']);
                $product->setNameB2b($this->getDefaultNameLanguageTranslations($value['name']['translations']));
                $product->setMaterial($this->getDefaultNameLanguageTranslations($value['material']['translations']));
                $product->setBrand($value['brand']['name']);
                $product->setShortDescriptionB2b($this->getDefaultNameLanguageTranslations($value['short_description']['translations']));
                $product->setDescriptionB2b($this->getDefaultNameLanguageTranslations($value['description']['translations']));
                $product->setSearch($value['search'] ?: null);
                $product->setPatternCode($value['pattern_code'] ?: null);
                $product->addProject($this->project);
                $product->setB2bId($value['id']);
                $this->em->persist($product);
                $this->writeDebugLog('product ' . $i . ' сборка данных');
                // Сохранение модели
                if (isset($value['models'])) {
                    $model = $this->getModel($value['models']);
                    $product->addModel($model);
                }
                $this->writeDebugLog('product ' . $i . ' модель');
                // Сохранение категорий
                if (isset($value['categories'])) {
                    $this->saveProductCategory($value['categories'], $product);
                }
                $this->writeDebugLog('product ' . $i . ' категории');
                // Сохранение переводов
                $brand = [];
                $search = [];
                foreach ($value['name']['translations'] as $lang => $translation) {
                    $brand[$lang] = $value['brand']['name'] ?? '';
                    $search[$lang] = $value['search'] ?? '';
                }
                $translatableFields = [
                    'brand' => $brand,
                    'name_b2b'              => $value['name']['translations'],
                    'size'                  => $value['size']['translations'],
                    'material'              => $value['material']['translations'],
                    'search' => $search,
                    'description_b2b'       => $value['description']['translations'],
                    'short_description_b2b' => $value['short_description']['translations'],
                ];
                $this->saveFieldsTranslations($translatableFields, $product, 'product');
                $this->writeDebugLog('product ' . $i . ' переводы');
                // Сохранение иконок цвета
                if (isset($color, $model) && !empty($value['icon'])) {
                    $this->addIconColor($model, $value, $color);
                }
                $this->writeDebugLog('product ' . $i . ' иконка цвета');
                $this->em->persist($product);
                //Сохранение фото продукта
                if (!empty($value['photo'])) {
                    $this->addPhotoProduct($product, $value);
                }
                $this->writeDebugLog('product ' . $i . ' фотки');
                $this->em->persist($product);
                $this->writeDebugLog('product ' . $i . ' persist');
                $this->productsObject[$product->getB2bId()] = $product;
                $this->productsMatrix[] = [
                    'id'        => $product->getB2bId(),
                    'matrix_id' => $product->getId(),
                ];
                $this->lastIdProduct = $product->getB2bId();
                $this->writeDebugLog('product ' . $i . ' end');
            } catch (Exception $e) {
                $this->writeLog('product ' . $i . ' error ' . $e->getMessage());
            }
            if ($i % 100 === 0) {
                $this->writeLog('product ' . $i . ' from ' . $allCount);
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    protected function saveProductCategory($data, Product $product): void
    {
        $uniqueCategoriesProducts = [];
        $categoryProductsObj = $product->getCategories();

        foreach ($data as $category) {
            $category = $this->categoriesObject[$category] ?? $this->getObjectCategory(['id' => $category]);
            if ($category) {
                $product->addCategory($category);
                $uniqueCategoriesProducts[$category->getId()] = $category;
            }
        }

        foreach ($categoryProductsObj as $categoryProductObj) {
            if (!isset($uniqueCategoriesProducts[$categoryProductObj->getId()])) {
                $product->removeCategory($categoryProductObj);
            }
        }
    }

    protected function saveModelCategory($data, Model $model): void
    {
        $uniqueCategoriesModel = [];
        $categoryModelsObj = $this->em->getRepository(CategoryModel::class)->findBy(['model' => $model]);

        foreach ($data as $category) {
            $category = $this->categoriesObject[$category] ?? $this->getObjectCategory(['id' => $category]);

            if ($category) {
                $categoryModel = $this->em->getRepository(CategoryModel::class)->findOneBy(
                    [
                        'model' => $model,
                        'category' => $category
                    ]
                );

                if (!isset($categoryModel)) {
                    $categoryModel = new CategoryModel();
                    $categoryModel->setCategory($category);
                    $categoryModel->setModel($model);
                    $categoryModel->setPosition(0);
                    $this->em->persist($categoryModel);
                }
                $uniqueCategoriesModel[$categoryModel->getId()] = $categoryModel;
            }
        }

        foreach ($categoryModelsObj as $categoryModelObj) {
            if (!isset($uniqueCategoriesModel[$categoryModelObj->getId()])) {
                $this->em->remove($categoryModelObj);
            }
        }
    }

    /**
     * @throws \Exception
     */
    private function getModel($value) {
        if (isset($this->modelObject[$value['id']])) {
            $model = $this->modelObject[$value['id']];
        } else {
            $model = $this->getObjectModel($value);
            $model->setB2bId($value['id']);
            $model->setSkuMf($value['sku_mf'] ?? '');
            $model->setIsActive($value['is_active']);
            $model->addProject($this->project);
            $this->writeDebugLog('model ' . $value['id'] . ' создание модели');
            if ($type = $this->getType($value['type'])) {
                $model->setType($type);
            }
            $this->writeDebugLog('model ' . $value['id'] . ' записали тип');
            $model->setTypeCode($value['type_code']);
            $model->setNewFrom($this->getDateTime($value['new_period']['from'], self::DATE_FORMAT_SHORT));
            $model->setNewTo($this->getDateTime($value['new_period']['to'], self::DATE_FORMAT_SHORT));
            $model->setPreorderFrom($this->getDateTime($value['preorder_period']['from'], self::DATE_FORMAT_SHORT));
            $model->setPreorderTo($this->getDateTime($value['preorder_period']['to'], self::DATE_FORMAT_SHORT));

            if ($createdAt = $this->getDateTime($value['created_at'], self::DATE_FORMAT_FULL)) {
                $model->setCreatedAt($createdAt);
            }
            $model->setDensity($value['density']);
            $model->setNameB2b($this->getDefaultNameLanguageTranslations($value['name']['translations']));
            $model->setMaterial($this->getDefaultNameLanguageTranslations($value['material']['translations']));
            $model->setBrand($value['brand']['name']);
            $model->setShortDescriptionB2b($this->getDefaultNameLanguageTranslations($value['short_description']['translations']));
            $model->setDescriptionB2b($this->getDefaultNameLanguageTranslations($value['description']['translations']));

            $model->setPatternCode($value['pattern_code'] ?: null);
            $this->writeDebugLog('model ' . $value['id'] . ' сборка данных');
            $this->em->persist($model);
            $this->writeDebugLog('model ' . $value['id'] . ' persist');

            // Сохранение категорий
            if (isset($value['categories'])) {
                $this->saveModelCategory($value['categories'], $model);
            }
            $this->writeDebugLog('model ' . $value['id'] . ' добавление категорий');
            $this->em->persist($model);
            // Сохранение переводов
            $brand = [];
            $search = [];
            foreach ($value['name']['translations'] as $lang => $translation) {
                $brand[$lang] = $value['brand']['name'] ?? '';
                $search[$lang] = $value['search'] ?? '';
            }
            $translatableFields = [
                'brand' => $brand,
                'name_b2b' => $value['name']['translations'],
                'material' => $value['material']['translations'],
                'search' => $search,
                'description_b2b' => $value['description']['translations'],
                'short_description_b2b' => $value['short_description']['translations'],
            ];
            $this->saveFieldsTranslations($translatableFields, $model, 'model');
            $this->writeDebugLog('model ' . $value['id'] . ' добавление переводов');

            // Сохранение фото
            if (!empty($value['images'])){
                $this->addPhotoModel($model, $value['images']);
            }
            $this->writeDebugLog('model ' . $value['id'] . ' сохранение фото');

            $this->em->persist($model);
            $this->writeDebugLog('model ' . $value['id'] . ' persist 2');
            $this->modelObject[$model->getB2bId()] = $model;
            $this->modelsMatrix[] = [
                'id' => $model->getB2bId(),
                'matrix_id' => $model->getId()
            ];
            $this->writeDebugLog('model ' . $value['id'] . ' end');
        }
        return $model;
    }

    private function getObjectModel($array) {
        $data = [
            'id' => $array['matrix_id'] ?? null,
            'b2b_id' => $array['id'] ?? null,
            'sku_mf' => $array['sku_mf'] ?? null
        ];

        foreach ($data as $key => $value) {
            if ($value) {
                $object = $this->em->getRepository(Model::class)->findOneBy([$key => $value]);
                if ($object) {
                    $this->modelObject[$object->getB2bId()] = $object;
                    break;
                }
            }
        }

        if (!isset($object)) {
            $object = new Model();
        }

        return $object;
    }

    private function getDefaultNameLanguageTranslations($translations) {

       return ($translations[$this->defaultLanguageCode]) ?? '';
    }

    private function getColor($data) {
        $color = null;
        if (isset($data['code'], $data['translations'])){
            if (isset($this->colorObject[$data['code']])) {
                return $this->colorObject[$data['code']];
            }
            $color = $this->em->getRepository(Color::class)->findOneBy(['code' => $data['code']]);

            if (!$color) {
                $color = new Color();
                $color->setCode($data['code']);
            }

            $color->setName($data['translations'][$this->defaultLanguageCode]);
            $this->em->persist($color);
            $this->addTranslationsFields($color, $data['translations'], 'color', 'name');

            $this->em->persist($color);

            $this->colorObject[$color->getCode()] = $color;
        }
        return $color;
    }

    private function getObjectProduct($array): Product
    {
        $data = [
            'id' => $array['matrix_id'] ?? null,
            'b2b_id' => $array['id'] ?? null,
            'sku' => $array['sku'] ?? null,
        ];

        foreach ($data as $key => $value) {
            if ($value) {
                $object = $this->em->getRepository(Product::class)->findOneBy([$key => $value]);
                if ($object) {
                    $this->productsObject[$object->getB2bId()] = $object;
                    break;
                }
            }
        }

        if (!isset($object)) {
            $object = new Product();
        }

        return $object;
    }

    private function getCategoryByTree($value): Category
    {
        if (isset($this->categoriesObject[$value['id']])) {
            $category = $this->categoriesObject[$value['id']];
        } else {
            $category = $this->categoriesMatrixObjects[$value['id']] ?? $this->getObjectCategory($value) ?? new Category();

            $category->setName($value['name'] ?? ' ');
            $category->setB2bId($value['id']);
            $category->setProject($this->project);
            $category->setPosition($this->lastCategoryIndex);
            $category->setIsSystem(isset($value['name']) && in_array(mb_strtolower($value['name']), $this->systemCategoriesNames, true));

            $this->lastCategoryIndex++;

            if (isset($value['parent_id'])) {
                $category->setParent(isset($this->categoriesImport[$value['parent_id']]) ? $this->getCategoryByTree($this->categoriesImport[$value['parent_id']]) : null);
            }
            $this->em->persist($category);

            $this->addTranslationsFields($category, $value['translation'], 'category', 'name');

            $this->em->persist($category);

            $this->categoriesObject[$value['id']] = $category;
            $this->categoriesMatrix[] = [
              'id' => $category->getB2bId(),
              'matrix_id' => $category->getId()
            ];
        }
        return $category;
    }


    private function getObjectCategory($array)
    {
        $object = null;
        $data = [
            'id' => $array['matrix_id'] ?? null,
            'b2b_id' => $array['id'] ?? null,
        ];
        foreach ($data as $key => $value) {
            if ($value) {
                $object = $this->em->getRepository(Category::class)->findOneBy([$key => $value]);
                if ($object) {
                    $this->categoriesObject[$object->getB2bId()] = $object;
                    break;
                }
            }
        }

        return $object;
    }

    private function getObjectType($array)
    {
        $data = [
            'id' => $array['matrix_id'] ?? null,
            'b2b_id' => $array['id'] ?? null,
            'name' => $array['name'] ?? null
        ];

        foreach ($data as $key => $value) {
            if ($value) {
                $object = $this->em->getRepository(Type::class)->findOneBy([$key => $value]);
                if ($object) {
                    $this->typesObject[$object->getB2bId()] = $object;
                    break;
                }
            }
        }

        if (!isset($object)) {
            $object = new Type();
        }

        return $object;
    }

    /**
     * Для массива. Подставляет в ключ элемента массива нужный индекс.
     */
    private function keyIndexArray($array)
    {
        $result = null;
        foreach ($array as $value) {
            $result[$value['id']] = $value;
        }

        return $result;
    }

    /**
     * Для объектов. Подставляет в ключ элемента массива нужный индекс.
     */
    private function keyIndexObject($objects, $field)
    {
        $result = null;
        $getter = 'get' . ucfirst($field);
        foreach ($objects as $object) {
            $result[$object->$getter()] = $object;
        }

        return $result;
    }

    /**
     * Выполняет добавление переводов полям
     */
    private function addTranslationsFields($entity, $data, $fields, $fieldsEntity): void
    {
         if (empty($data)) {
             return;
         }
         foreach ($data as $locale => $translate) {

             $languageObj = $this->getLanguage($locale);

             if (empty($languageObj) || empty(trim($translate))) {
                 continue;
             }

             if (isset($this->translationObject[$languageObj->getId()][$fields][$entity->getId()][$fieldsEntity])) {
                 continue;
             }

             $setter = 'set' . ucfirst($fields);

             $translation = $this->em->getRepository(Translation::class)->findOneBy([
                 'language' => $languageObj,
                 'field' => $fieldsEntity,
                 $fields => $entity
             ]);
             if (empty($translation)) {
                 $translation = new Translation();
             }

             $translation->setField($fieldsEntity);
             $translation->setLanguage($languageObj);
             $translation->$setter($entity);
             $translation->setTranslation($translate);

             $this->em->persist($translation);
             $this->translationObject[$languageObj->getId()][$fields][$entity->getId()][$fieldsEntity] = $translate;
         }
    }

    /**
     * @param $locale
     *
     * @return false|mixed
     */
    private function getLanguage($locale)
    {
        return $this->languages->filter(fn(Language $language) => $language->getCode() === $locale)->first();
    }

    protected function getBeforeData(): void
    {
        $this->project = $this->em->getRepository(Project::class)->findOneBy(['slug' => 'b2b']);
        $this->languages = new ArrayCollection($this->em->getRepository(Language::class)->findAll());
        $this->counterCodeType = $this->plainQueryService->maxId('Type');
        $this->defaultLanguageCode = $this->container->getParameter('default_language_code');
        $this->colorImages = $this->em->getRepository(ColorImage::class)->findAll();
        $this->productsImages = $this->em->getRepository(ProductImage::class)->findAll();
    }

    /**
     * @param string|integer $string
     * @param null $format
     * @return \DateTime|null
     */
    public function getDateTime($string, $format = null)
    {
        $date = null;

        if(!empty($string)) {
            try {
                if ((string)(int)$string === (string)($string)) {
                    $date = (new \DateTime)->setTimestamp($string);
                } else {
                    $date = new \DateTime($string);
                }
            } catch (\Exception $e) {
                $date = null;
            }
        }

        if($date && $format){
            $date = $date->format($format);
        }

        return $date;
    }

    /**
     * Сохраняет переводы для переданной сущности
     * @throws Exception
     */
    private function saveFieldsTranslations($translatableFields, $entity, $type): void
    {
        foreach ($translatableFields as $nameFields => $array) {
            $this->addTranslationsFields($entity, $array, $type, $nameFields);
        }
    }

    /**
     * В случае успеха скачивает изображение по ссылке и возвращает объект App/File.
     * В противном случае возвращает false.
     *
     * @param $url
     * @param $hash
     * @param $savePath
     * @return File|false
     */
    private function getFileByUrl($url, $hash, $savePath)
    {
        $filename = basename($url);
        $imageSavePath = $savePath . $filename;

        $file = $this->getUniqueFile($hash);

        if (!isset($file)) {
            // Если файл уже существует -- не скачиваем его
            if(!file_exists($imageSavePath)) {
                try {
                    $fileContent = file_get_contents($url);
                } catch (Exception $exception) {
                    return false;
                }

                if(empty($fileContent) || !file_put_contents($imageSavePath, $fileContent)) {
                    return false;
                }

                $this->optimizer->optimize($imageSavePath);
            }

            $file = new File();
            $file->setName(basename($imageSavePath));
            $file->setSize(filesize($imageSavePath));
            $file->setHash($hash);
            $file->setMimetype(mime_content_type($imageSavePath));
            $file->setPath(
                $this->params->get('products_images_url') . $file->getName()
            );

            $this->em->persist($file);
        }

        return $file;
    }

    private function getUniqueFile($hash) {

        if (isset($this->photoHashArray[$hash])) {
            return $this->photoHashArray[$hash];
        }

        $file = $this->em->getRepository(File::class)->findOneBy([
            'hash' => $hash
        ]);

        return $file ?? null;
    }

    protected function addIconColor(Model $model, $array, Color $color): void
    {
        if ($model) {
            $colorImage = array_values(array_filter(
                $this->colorImages,
                fn(ColorImage $ci) =>
                    !empty($ci->getModel())
                    && !empty($ci->getColor())
                    && $ci->getModel()->getId() == $model->getId()
                    && $ci->getColor()->getId() == $color->getId()
            ));

            if(count($colorImage)) {
                $colorImage = $colorImage[0];
            } else {
                $colorImage = new ColorImage();
                $colorImage->setModel($model);
                $colorImage->setColor($color);

                $this->em->persist($colorImage);
                $this->colorImages[] = $colorImage;
            }
        } else {
            $colorImage = array_values(array_filter(
                $this->colorImages,
                fn(ColorImage $ci) =>
                    empty($ci->getModel())
                    && !empty($ci->getColor())
                    && $ci->getColor()->getId() == $color->getId()
            ));

            if(count($colorImage)) {
                $colorImage = $colorImage[0];
            } else {
                $colorImage = new ColorImage();
                $colorImage->setColor($color);

                $this->em->persist($colorImage);
                $this->colorImages[] = $colorImage;
            }
        }

        $file = $this->getFileByUrl(
            $array['icon'][0]['path'],
            $array['icon'][0]['hash'],
            $this->params->get('products_images_directory')
        );

        if(!$file) {
            return;
        }

        $this->photoHashArray[$array['icon'][0]['hash']] = $file;

        $this->em->persist($file);
        $colorImage->setFile($file);
        $this->em->persist($colorImage);
    }

    protected function addPhotoProduct(Product $product, $array): void
    {
            foreach ($array['photo'] as $key => $photo) {
                $file = $this->getFileByUrl(
                    $photo['path'],
                    $photo['hash'],
                    $this->params->get('products_images_directory')
                );

                if(!$file) {
                    return;
                }

                $this->photoHashArray[$photo['hash']] = $file;

                $productImage = new ProductImage();
                $productImage->setProduct($product);
                $productImage->setPosition($key);
                $productImage->setFile($file);
                $productImage->setIsGeneral(!$key);

                $this->em->persist($productImage);
            }
    }

    protected function addPhotoModel(Model $model, $array): void
    {
        foreach ($array as $key => $images) {
            if (!empty($images)) {
                foreach ($images as $k => $image) {
                    $file = $this->getFileByUrl(
                        $image['path'],
                        $image['hash'],
                        $this->params->get('products_images_directory')
                    );

                    if(!$file) {
                        return;
                    }

                    $this->photoHashArray[$image['hash']] = $file;

                    $systemType = $this->imageTypesSlugs[$key][$k];
                    $type = array_values(array_filter($this->modelImageTypes, function (SystemType $item) use ($systemType) {
                        return $item->getSlug() === $systemType;
                    }))[0];

                    $modelImage = new ModelImage();
                    $modelImage->setModel($model);
                    $modelImage->setFile($file);
                    $modelImage->setSystemType($type);

                    $this->em->persist($modelImage);
                }
            }
        }
    }

    /**
     * @param $message
     */
    protected function writeLog($message): void
    {
        $memory = memory_get_usage();
        $message = "[$this->sessionId] $message : memory: $memory";
        $this->logger->info($message);
    }

    /**
     * @param $message
     *
     * @return void
     */
    private function writeDebugLog($message): void
    {
        if (self::DEBUG_LOG) {
            $this->writeLog($message);
        }
    }
}

