<?php
/*---------------------------------------------------------------------------
 * @Project: Alto CMS
 * @Project URI: http://altocms.com
 * @Description: Advanced Community Engine
 * @Copyright: Alto CMS Team
 * @License: GNU GPL v2 & MIT
 *----------------------------------------------------------------------------
 * Based on
 *   LiveStreet Engine Social Networking by Mzhelskiy Maxim
 *   Site: www.livestreet.ru
 *   E-mail: rus.engine@gmail.com
 *----------------------------------------------------------------------------
 */

/**
 * ActionUploader.class.php
 * Файл экшена загрузчика файлов
 *
 * @package actions
 * @since   1.1
 */
class ActionUploader extends Action {

    const PREVIEW_RESIZE = 222;

    const OK = 200;
    const ERROR = 500;

    /**
     * Абстрактный метод инициализации экшена
     *
     */
    public function Init() {
        // TODO: Implement Init() method.
    }

    /**
     * Абстрактный метод регистрации евентов.
     * В нём необходимо вызывать метод AddEvent($sEventName,$sEventFunction)
     * Например:
     *      $this->AddEvent('index', 'EventIndex');
     *      $this->AddEventPreg('/^admin$/i', '/^\d+$/i', '/^(page([1-9]\d{0,5}))?$/i', 'EventAdminBlog');
     */
    protected function RegisterEvent() {

        $this->AddEventPreg('/^upload-image/i', '/^$/i', 'EventUploadImage'); // Загрузка изображения на сервер
        $this->AddEventPreg('/^resize-image/i', '/^$/i', 'EventResizeImage'); // Ресайз изображения
        $this->AddEventPreg('/^remove-image-by-id/i', '/^$/i', 'EventRemoveImageById'); // Удаление изображения по его идентификатору
        $this->AddEventPreg('/^remove-image/i', '/^$/i', 'EventRemoveImage'); // Удаление изображения
        $this->AddEventPreg('/^cancel-image/i', '/^$/i', 'EventCancelImage'); // Отмена ресайза в окне, закрытие окна ресайза
        $this->AddEventPreg('/^direct-image/i', '/^$/i', 'EventDirectImage'); // Прямая загрузка изображения без открытия окна ресайза
        $this->AddEventPreg('/^multi-image/i', '/^$/i', 'EventMultiUpload'); // Прямая загрузка нескольких изображений
        $this->AddEvent('description', 'EventDescription'); // Установка описания ресурса
        $this->AddEvent('cover', 'EventCover'); // Установка обложки фотосета
        $this->AddEvent('sort', 'EventSort'); // Меняет сортировку элементов фотосета

    }

    /**
     * Получение размеров изображения после ресайза
     *
     * @param $sParam
     * @return array|mixed
     */
    protected function _getImageSize($sParam) {

        if ($aSize = F::GetRequest($sParam)) {
            if (isset($aSize['x']) && is_numeric($aSize['x']) && isset($aSize['y']) && is_numeric($aSize['y'])
                && isset($aSize['x2']) && is_numeric($aSize['x2']) && isset($aSize['y2']) && is_numeric($aSize['y2'])
            ) {
                foreach ($aSize as $sKey => $sVal) {
                    $aSize[$sKey] = intval($sVal);
                }
                if ($aSize['x'] < $aSize['x2']) {
                    $aSize['x1'] = $aSize['x'];
                } else {
                    $aSize['x1'] = $aSize['x2'];
                    $aSize['x2'] = $aSize['x'];
                }
                $aSize['w'] = $aSize['x2'] - $aSize['x1'];
                unset($aSize['x']);
                if ($aSize['y'] < $aSize['y2']) {
                    $aSize['y1'] = $aSize['y'];
                } else {
                    $aSize['y1'] = $aSize['y2'];
                    $aSize['y2'] = $aSize['y'];
                }
                $aSize['h'] = $aSize['y2'] - $aSize['y1'];
                unset($aSize['y']);

                return $aSize;
            }
        }

        return array();
    }

    /**
     * Добавляет связь между объектом и ресурсом
     *
     * @param $xStoredFile
     * @param $sTargetId
     * @param $sTargetType
     * @param bool $bMulti
     * @return bool
     */
    public function AddUploadedFileRelationInfo($xStoredFile, $sTargetId, $sTargetType, $bMulti = FALSE) {

        // Если одиночная загрузка, то предыдущий файл затрем
        // Иначе просто добавляем еще один.
        if (!$bMulti) {
            E::ModuleMresource()->UnlinkFile($sTargetType, $sTargetId, E::UserId());
        }

        /** @var ModuleMresource_EntityMresource $oResource */
        $oResource = E::ModuleMresource()->GetMresourcesByUuid($xStoredFile->getUuid());
        if ($oResource) {
//            $oRel = Engine::GetEntity('Mresource_MresourceRel');
            $oResource->setUrl(E::ModuleMresource()->NormalizeUrl(E::ModuleUploader()->GetTargetUrl($sTargetId, $sTargetType)));
            $oResource->setType($sTargetType);
            $oResource->setUserId(E::UserId());
            if ($sTargetId == '0') {
                $oResource->setTargetTmp(E::ModuleSession()->GetCookie('uploader_target_tmp'));
            }
            $oResource = array($oResource);

            E::ModuleMresource()->AddTargetRel($oResource, $sTargetType, $sTargetId);

            return $oResource;
        }

        return FALSE;
    }

    /**
     * Прямая загрузка изображения без открытия окна ресайза
     */
    public function EventDirectImage() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // * Достаем из сессии временный файл
        $sTarget = E::ModuleSession()->Get('sTarget');
        $sTargetId = E::ModuleSession()->Get('sTargetId');
        $sTmpFile = E::ModuleSession()->Get("sTmp-{$sTarget}-{$sTargetId}");
        $sPreviewFile = E::ModuleSession()->Get("sPreview-{$sTarget}-{$sTargetId}");

        if ($sTargetId == '0') {
            if (!E::ModuleSession()->GetCookie('uploader_target_tmp')) {
                return FALSE;
            }
        }

        if (!F::File_Exists($sTmpFile)) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('system_error'));

            return;
        }

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget($sTarget, $sTargetId)) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        E::ModuleMresource()->UnlinkFile($sTarget, $sTargetId, E::UserId());

        $oImg = E::ModuleImg()->Read($sTmpFile);

        $sExtension = strtolower(pathinfo($sTmpFile, PATHINFO_EXTENSION));

        // Сохраняем фото во временный файл
        if ($sTmpFile = $oImg->Save(F::File_UploadUniqname($sExtension))) {

            // Файл, куда будет записано фото
            $sPhoto = E::ModuleUploader()->Uniqname(E::ModuleUploader()->GetUploadDir($sTargetId, $sTarget), $sExtension);

            // Окончательная запись файла только через модуль Uploader
            if ($xStoredFile = E::ModuleUploader()->Store($sTmpFile, $sPhoto)) {

                if (is_object($xStoredFile)) {

                    $this->AddUploadedFileRelationInfo($xStoredFile, $sTargetId, $sTarget);
                    $sFile = $xStoredFile->GetUrl();

                } else {
                    $sFile = $xStoredFile->GetUrl();
                }

                $sFilePreview = $sFile;
                if ($sSize = F::GetRequest('crop_size', FALSE)) {
                    $sFilePreview = E::ModuleUploader()->ResizeTargetImage($sFile, $sSize);
                }

                // Запускаем хук на действия после загрузки картинки
                E::ModuleHook()->Run('uploader_upload_image_after', array(
                    'sFile'        => $sFile,
                    'sFilePreview' => $sFilePreview,
                    'sTargetId'    => $sTargetId,
                    'sTarget'      => $sTarget,
                    'oTarget'      => $oTarget,
                ));

                E::ModuleViewer()->AssignAjax('sFile', $sFile);
                E::ModuleViewer()->AssignAjax('sFilePreview', $sFilePreview);

                // Чистим
                $sTmpFile = E::ModuleSession()->Get("sTmp-{$sTarget}-{$sTargetId}");
                $sPreviewFile = E::ModuleSession()->Get("sPreview-{$sTarget}-{$sTargetId}");
                E::ModuleImg()->Delete($sTmpFile);
                E::ModuleImg()->Delete($sPreviewFile);

                // * Удаляем из сессии
                E::ModuleSession()->Drop('sTarget');
                E::ModuleSession()->Drop('sTargetId');
                E::ModuleSession()->Drop("sTmp-{$sTarget}-{$sTargetId}");
                E::ModuleSession()->Drop("sPreview-{$sTarget}-{$sTargetId}");

                return;
            }
        }

        // * В случае ошибки, возвращаем false
        E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('system_error'));

        return;
    }

    /**
     * Загрузка изображения после его ресайза
     *
     * @param  string $sFile - Серверный путь до временной фотографии
     * @param  string $sTargetId - Ид. целевого объекта
     * @param  string $sTarget - Тип целевого объекта
     * @param  array $aSize - Размер области из которой нужно вырезать картинку - array('x1'=>0,'y1'=>0,'x2'=>100,'y2'=>100)
     *
     * @return string|bool
     */
    public function UploadImageAfterResize($sFile, $sTargetId, $sTarget, $aSize = array()) {

        if ($sTargetId == '0') {
            if (!E::ModuleSession()->GetCookie('uploader_target_tmp')) {
                return FALSE;
            }
        }

        if (!F::File_Exists($sFile)) {
            return FALSE;
        }
        if (!$aSize) {
            $oImg = E::ModuleImg()->CropSquare($sFile, TRUE);
        } else {
            if (!isset($aSize['w'])) {
                $aSize['w'] = $aSize['x2'] - $aSize['x1'];
            }
            if (!isset($aSize['h'])) {
                $aSize['h'] = $aSize['y2'] - $aSize['y1'];
            }
            $oImg = E::ModuleImg()->Crop($sFile, $aSize['w'], $aSize['h'], $aSize['x1'], $aSize['y1']);
        }
        $sExtension = strtolower(pathinfo($sFile, PATHINFO_EXTENSION));

        // Сохраняем фото во временный файл
        if ($sTmpFile = $oImg->Save(F::File_UploadUniqname($sExtension))) {

            // Файл, куда будет записано фото
            $sPhoto = E::ModuleUploader()->Uniqname(E::ModuleUploader()->GetUploadDir($sTargetId, $sTarget), $sExtension);

            // Окончательная запись файла только через модуль Uploader
            if ($xStoredFile = E::ModuleUploader()->Store($sTmpFile, $sPhoto)) {

                if (is_object($xStoredFile)) {

                    $this->AddUploadedFileRelationInfo($xStoredFile, $sTargetId, $sTarget);
                    $sFile = $xStoredFile->GetUrl();

                } else {
                    $sFile = $xStoredFile->GetUrl();
                }

                return $sFile;
            }
        }

        // * В случае ошибки, возвращаем false
        E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('system_error'));

        return FALSE;
    }

    /**
     * Загружаем картинку
     */
    public function EventUploadImage() {

        // Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json', FALSE);

        E::ModuleSecurity()->ValidateSendForm();

        // Проверяем, загружен ли файл
        if (!($aUploadedFile = $this->GetUploadedFile('uploader-upload-image'))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('error_upload_image'), E::ModuleLang()->Get('error'));

            return;
        }

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTarget = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            // Здесь два варианта, либо редактировать нельзя, либо можно, но топика еще нет
            if ($oTarget === TRUE) {
                // Будем делать временную картинку

            } else {
                E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

                return;
            }

        }

        // Ошибок пока нет
        $sError = '';

        // Сделаем временный файд
        $sTmpFile = E::ModuleUploader()->UploadLocal($aUploadedFile);

        // Вызовем хук перед началом загрузки картинки
        E::ModuleHook()->Run('uploader_upload_before', array('oTarget' => $oTarget, 'sTmpFile' => $sTmpFile, 'sTarget' => $sTarget));

        // Если все ок, и по миме проходит, то
        if ($sTmpFile && E::ModuleImg()->MimeType($sTmpFile)) {

            // Ресайзим и сохраняем уменьшенную копию
            // Храним две копии - мелкую для показа пользователю и крупную в качестве исходной для ресайза
            $sPreviewFile = E::ModuleUploader()->GetUploadDir($sTargetId, $sTarget) . '_preview.' . F::File_GetExtension($sTmpFile);

            if ($sPreviewFile = E::ModuleImg()->Copy($sTmpFile, $sPreviewFile, self::PREVIEW_RESIZE, self::PREVIEW_RESIZE)) {

                // * Сохраняем в сессии временный файл с изображением
                E::ModuleSession()->Set('sTarget', $sTarget);
                E::ModuleSession()->Set('sTargetId', $sTargetId);
                E::ModuleSession()->Set("sTmp-{$sTarget}-{$sTargetId}", $sTmpFile);
                E::ModuleSession()->Set("sPreview-{$sTarget}-{$sTargetId}", $sPreviewFile);
                E::ModuleViewer()->AssignAjax('sPreview', E::ModuleUploader()->Dir2Url($sPreviewFile));

                if (getRequest('direct', FALSE)) {
                    $this->EventDirectImage();
                }

                return;
            }
        } else {

            // Ошибки загрузки картинки
            $sError = E::ModuleUploader()->GetErrorMsg();
            if (!$sError) {
                $sError = E::ModuleLang()->Get('error_upload_image');
            }
        }

        // Выведем ошибки пользователю
        E::ModuleMessage()->AddError($sError, E::ModuleLang()->Get('error'));

        // Удалим ранее загруженый файл
        F::File_Delete($sTmpFile);

    }

    /**
     * Обработка обрезки изображения
     */
    public function EventResizeImage() {
        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // * Достаем из сессии временный файл
        $sTarget = E::ModuleSession()->Get('sTarget');
        $sTargetId = E::ModuleSession()->Get('sTargetId');
        $sTmpFile = E::ModuleSession()->Get("sTmp-{$sTarget}-{$sTargetId}");
        $sPreviewFile = E::ModuleSession()->Get("sPreview-{$sTarget}-{$sTargetId}");

        if (!F::File_Exists($sTmpFile)) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('system_error'));

            return;
        }

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget($sTarget, $sTargetId)) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        // * Определяем размер большого фото для подсчета множителя пропорции
        $fRation = 1;
        if (($aSizeFile = getimagesize($sTmpFile)) && isset($aSizeFile[0])) {
            // в self::PREVIEW_RESIZE задана максимальная сторона
            $fRation = max($aSizeFile[0], $aSizeFile[1]) / self::PREVIEW_RESIZE; // 222 - размер превью по которой пользователь определяет область для ресайза
            if ($fRation < 1) {
                $fRation = 1;
            }
        }

        // * Получаем размер области из параметров
        $aSize = $this->_getImageSize('size');
        if ($aSize) {
            $aSize = array(
                'x1' => round($fRation * $aSize['x1']), 'y1' => round($fRation * $aSize['y1']),
                'x2' => round($fRation * $aSize['x2']), 'y2' => round($fRation * $aSize['y2'])
            );
        }

        // * Вырезаем и сохраняем фото
        if ($sFileWeb = $this->UploadImageAfterResize($sTmpFile, $sTargetId, $sTarget, $aSize)) {

            $sFileWebPreview = $sFileWeb;
            if ($sSize = F::GetRequest('crop_size', FALSE)) {
                $sFileWebPreview = E::ModuleUploader()->ResizeTargetImage($sFileWeb, $sSize);
            }

            // Запускаем хук на действия после загрузки картинки
            E::ModuleHook()->Run('uploader_upload_image_after', array(
                'sFile'        => $sFileWeb,
                'sFilePreview' => $sFileWebPreview,
                'sTargetId'    => $sTargetId,
                'sTarget'      => $sTarget,
                'oTarget'      => $oTarget,
            ));

            E::ModuleImg()->Delete($sTmpFile);
            E::ModuleImg()->Delete($sPreviewFile);

            // * Удаляем из сессии
            E::ModuleSession()->Drop('sTarget');
            E::ModuleSession()->Drop('sTargetId');
            E::ModuleSession()->Drop("sTmp-{$sTarget}-{$sTargetId}");
            E::ModuleSession()->Drop("sPreview-{$sTarget}-{$sTargetId}");

            E::ModuleViewer()->AssignAjax('sFile', $sFileWeb);
            E::ModuleViewer()->AssignAjax('sFilePreview', $sFileWebPreview);
            E::ModuleViewer()->AssignAjax('sTitleUpload', E::ModuleLang()->Get('uploader_upload_success'));
        } else {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('error_upload_image'), E::ModuleLang()->Get('error'));
        }
    }

    /**
     * Удаление картинки
     */
    public function EventRemoveImage() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTargetType = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        // * Удаляем картинку
        E::ModuleMresource()->UnlinkFile($sTargetType, $sTargetId, E::UserId());

        // Запускаем хук на действия после загрузки картинки
        E::ModuleHook()->Run('uploader_remove_image_after', array(
            'sTargetId' => $sTargetId,
            'sTarget'   => $sTargetType,
            'oTarget'   => $oTarget,
        ));

        // * Возвращает дефолтную аватарку
        E::ModuleViewer()->AssignAjax('sTitleUpload', E::ModuleLang()->Get('uploader_upload_success'));

    }

    /**
     * Отмена загрузки в окне ресайза
     */
    public function EventCancelImage() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTarget = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }
        $sTmpFile = E::ModuleSession()->Get("sTmp-{$sTarget}-{$sTargetId}");

        if (!F::File_Exists($sTmpFile)) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('system_error'));

            return;
        }

        E::ModuleImg()->Delete($sTmpFile);

        // * Удаляем из сессии
        E::ModuleSession()->Drop('sTarget');
        E::ModuleSession()->Drop('sTargetId');
        E::ModuleSession()->Drop("sTmp-{$sTarget}-{$sTargetId}");
        E::ModuleSession()->Drop("sPreview-{$sTarget}-{$sTargetId}");

    }

    /**
     * Загружаем картинку
     */
    public function EventMultiUpload() {

        // Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json', FALSE);

        E::ModuleSecurity()->ValidateSendForm();

        // Проверяем, загружен ли файл
        if (!($aUploadedFile = $this->GetUploadedFile('uploader-upload-image'))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('error_upload_image'), E::ModuleLang()->Get('error'));

            return;
        }

        $sTarget = F::GetRequest('target', FALSE);
        $sTargetId = F::GetRequest('target_id', FALSE);
        $oTarget = E::ModuleUploader()->CheckAccessAndGetTarget($sTarget, $sTargetId);

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget) {
            // Здесь два варианта, либо редактировать нельзя, либо можно, но топика еще нет
            if ($oTarget === TRUE) {
                // Будем делать временную картинку

            } else {
                E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

                return;
            }

        }

        // Ошибок пока нет
        $sError = '';

        // Сделаем временный файд
        $sTmpFile = E::ModuleUploader()->UploadLocal($aUploadedFile);

        // Вызовем хук перед началом загрузки картинки
        E::ModuleHook()->Run('uploader_upload_before', array('oTarget' => $oTarget, 'sTmpFile' => $sTmpFile, 'sTarget' => $sTarget));

        // Если все ок, и по миме проходит, то
        if ($sTmpFile && E::ModuleImg()->MimeType($sTmpFile)) {

            // Проверим, проходит ли по количеству
            if (!E::ModuleUploader()->GetAllowedCount(
                $sTarget = F::GetRequest('target', FALSE),
                $sTargetId = F::GetRequest('target_id', FALSE))
            ) {
                E::ModuleMessage()->AddError(E::ModuleLang()->Get(
                    'uploader_photoset_error_count_photos',
                    array('MAX' => Config::Get('module.topic.photoset.count_photos_max'))
                ), E::ModuleLang()->Get('error'));

                return FALSE;
            }

            // Определим, существует ли объект или он будет создан позже
            if (!($sTmpKey = E::ModuleSession()->GetCookie('uploader_target_tmp')) && $sTargetId == '0') {
                E::ModuleMessage()->AddError(E::ModuleLang()->Get('error_upload_image'), E::ModuleLang()->Get('error'));

                return FALSE;
            }

            // Пересохраним файл из кэша
            // Сохраняем фото во временный файл
            $oImg = E::ModuleImg()->Read($sTmpFile);
            $sExtension = strtolower(pathinfo($sTmpFile, PATHINFO_EXTENSION));
            if (!$sTmpFile = $oImg->Save(F::File_UploadUniqname($sExtension))) {
                E::ModuleMessage()->AddError(E::ModuleLang()->Get('error_upload_image'), E::ModuleLang()->Get('error'));

                return FALSE;
            }

            // Файл, куда будет записано фото
            $sPhoto = E::ModuleUploader()->Uniqname(E::ModuleUploader()->GetUploadDir($sTargetId, $sTarget), $sExtension);

            // Окончательная запись файла только через модуль Uploader
            if ($xStoredFile = E::ModuleUploader()->Store($sTmpFile, $sPhoto)) {

                if (is_object($xStoredFile)) {
                    /** @var ModuleMresource_EntityMresource $oResource */
                    $oResource = $this->AddUploadedFileRelationInfo($xStoredFile, $sTargetId, $sTarget, TRUE);
                    $sFile = $xStoredFile->GetUrl();
                    if ($oResource) {
                        $oResource = array_shift($oResource);
                        $oResource->setType(ModuleMresource::TYPE_PHOTO);
                        E::ModuleMresource()->UpdateType($oResource);
                    }
                } else {
                    E::ModuleMessage()->AddError(E::ModuleLang()->Get('error_upload_image'), E::ModuleLang()->Get('error'));

                    return FALSE;
                }

                $sFilePreview = $sFile;
                if ($sSize = F::GetRequest('crop_size', FALSE)) {
                    $sFilePreview = E::ModuleUploader()->ResizeTargetImage($sFile, $sSize);
                }

                // Запускаем хук на действия после загрузки картинки
                E::ModuleHook()->Run('uploader_upload_image_after', array(
                    'sFile'        => $sFile,
                    'sFilePreview' => $sFilePreview,
                    'sTargetId'    => $sTargetId,
                    'sTarget'      => $sTarget,
                    'oTarget'      => $oTarget,
                ));

                E::ModuleViewer()->AssignAjax('file', $sFilePreview);
                E::ModuleViewer()->AssignAjax('id', $oResource->getMresourceId());


                // Чистим
                E::ModuleImg()->Delete($sTmpFile);

                return TRUE;
            }

        } else {

            // Ошибки загрузки картинки
            $sError = E::ModuleUploader()->GetErrorMsg();
            if (!$sError) {
                $sError = E::ModuleLang()->Get('error_upload_image');
            }
        }

        // Выведем ошибки пользователю
        E::ModuleMessage()->AddError($sError, E::ModuleLang()->Get('error'));

        // Удалим ранее загруженый файл
        F::File_Delete($sTmpFile);

    }


    /**
     * Удаление картинки
     */
    public function EventRemoveImageById() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTargetType = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        if (!($sResourceId = F::GetRequest('resource_id', FALSE))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        if (!($oResource = E::ModuleMresource()->GetMresourceById($sResourceId))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        // Удалим ресурс без проверки связи с объектом. Объект-то останется, а вот
        // изображение нам уже ни к чему.
        E::ModuleMresource()->DeleteMresources($oResource, TRUE, TRUE);

        E::ModuleMessage()->AddNoticeSingle(E::ModuleLang()->Get('topic_photoset_photo_deleted'));

    }


    /**
     * Удаление картинки
     */
    public function EventDescription() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTargetType = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        if (!($sResourceId = F::GetRequest('resource_id', FALSE))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        /** @var ModuleMresource_EntityMresource $oResource */
        if (!($oResource = E::ModuleMresource()->GetMresourceById($sResourceId))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        $oResource->setDescription(F::GetRequestStr('description', ''));
        E::ModuleMresource()->UpdateParams($oResource);

        E::ModuleMessage()->AddNoticeSingle(E::ModuleLang()->Get('topic_photoset_description_done'));

    }


    /**
     * Удаление картинки
     */
    public function EventCover() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTargetType = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        if (!($sResourceId = F::GetRequest('resource_id', FALSE))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        /** @var ModuleMresource_EntityMresource $oResource */
        if (!($oResource = E::ModuleMresource()->GetMresourceById($sResourceId))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        $oResource->setType(ModuleMresource::TYPE_PHOTO_PRIMARY);
        E::ModuleMresource()->UpdatePrimary($oResource, $sTargetType, $sTargetId);

        E::ModuleMessage()->AddNoticeSingle(E::ModuleLang()->Get('topic_photoset_is_preview'));

    }


    /**
     * Меняет сортировку элементов фотосета
     */
    public function EventSort() {

        // * Устанавливаем формат Ajax ответа
        E::ModuleViewer()->SetResponseAjax('json');

        // Проверяем, целевой объект и права на его редактирование
        if (!$oTarget = E::ModuleUploader()->CheckAccessAndGetTarget(
            $sTargetType = F::GetRequest('target', FALSE),
            $sTargetId = F::GetRequest('target_id', FALSE))
        ) {
            E::ModuleMessage()->AddErrorSingle(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        if (!($aOrder = F::GetRequest('order', FALSE))) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        if (!is_array($aOrder)) {
            E::ModuleMessage()->AddError(E::ModuleLang()->Get('not_access'), E::ModuleLang()->Get('error'));

            return;
        }

        E::ModuleMresource()->UpdateSort(array_flip($aOrder), $sTargetType, $sTargetId);

        E::ModuleMessage()->AddNoticeSingle(E::ModuleLang()->Get('uploader_sort_changed'));

    }

}