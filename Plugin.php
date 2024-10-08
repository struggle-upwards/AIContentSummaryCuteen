<?php

/**
 * AIContentSummaryCuteen 是一个用于通过调用AI接口，根据文章内容生成摘要的 Typecho 插件，适配Cuteen
 *
 * @package AIContentSummaryCuteen
 * @author pioneerpan
 * @version 1.0
 * @link https://pioneerpan.cn
 */

 
class AIContentSummaryCuteen_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('AIContentSummaryCuteen_Plugin', 'onFinishPublish');
    }

    public static function deactivate()
    {
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 添加输入框：模型名
        $modelName = new Typecho_Widget_Helper_Form_Element_Text(
            'modelName',
            NULL,
            'gpt-3.5-turbo-16k',
            _t('请输入生成文章摘要使用的模型名'),
            _t('默认为gpt-3.5-turbo-16k')
        );
        $form->addInput($modelName);

        // 添加输入框：key值
        $apiKey = new Typecho_Widget_Helper_Form_Element_Text(
            'apiKey',
            NULL,
            NULL,
            _t('API KEY'),
            _t('输入调用API的key')
        );
        $apiKey->addRule("required",_t("API KEY不能为空"));
        $form->addInput($apiKey);

        // 添加输入框：API地址
        $apiUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'apiUrl',
            NULL,
            NULL,
            _t('请输入调用API的接口基地址'),
            _t('不要省略（https://）或（http://）不要带有（/v1），如：https://free.gpt.ge')
        );
        $apiUrl->addRule("required",_t("API的基地址不能为空"));
        $form->addInput($apiUrl);

        // 添加输入框：摘要最大长度
        $maxLength = new Typecho_Widget_Helper_Form_Element_Text(
            'maxLength',
            NULL,
            '100',
            _t('请输入生成文章摘要最大长度'),
            _t('默认文章摘要的最大文字数量为100')
        );
        $form->addInput($maxLength);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function onFinishPublish($contents, $obj)
    {
        $title = $contents['title'];
        $text = $contents['text'];
        $apiResponse = self::callApi($title, $text);

        // 保存摘要到自定义字段content
        $db = Typecho_Db::get();
        $rows = $db->fetchRow($db->select()->from('table.fields')->where('cid = ?', $obj->cid)->where('name = ?', 'excerpt'));
        if ($rows) {
            $db->query($db->update('table.fields')->rows(array('str_value' => $apiResponse))->where('cid = ?', $obj->cid)->where('name = ?', 'excerpt'));
        } else {
            $db->query($db->insert('table.fields')->rows(array('cid' => $obj->cid, 'name' => 'excerpt', 'type' => 'str', 'str_value' => $apiResponse, 'int_value' => 0, 'float_value' => 0)));
        }

        return $contents;
    }

    private static function callApi($title, $text)
    {
        // 获取用户填入的值
        $options = Typecho_Widget::widget('Widget_Options')->plugin('AIContentSummaryCuteen');
        $modelName = $options->modelName;
        $apiKey = $options->apiKey;
        $apiUrl = rtrim($options->apiUrl, '/') . '/v1/chat/completions';
        $maxLength = $options->maxLength;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $title = addslashes($title);
        $prompt = addslashes($text);

        $data = array(
            "model" => $modelName,
            "messages" => array(
                array(
                    "role" => "system",
                    "content" => "请你扮演一个文本摘要生成器，下面是一篇关于 ’{$title}‘ 的文章，请你根据文章内容生成 {$maxLength} 字左右的摘要，除了你生成的的摘要内容，请不要输出其他任何无关内容"
                ),
                array(
                    "role" => "user",
                    "content" => $prompt
                )
            ),
            "temperature" => 0
        );

        $maxRetries = 5;
        $retries = 0;
        $waitTime = 2;

        while ($retries < $maxRetries) {
            try {
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

                $response = curl_exec($ch);

                if (curl_errno($ch)) {
                    throw new Exception(curl_error($ch), curl_errno($ch));
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

                if ($httpCode == 200) {
                    $decodedResponse = json_decode($response, true);
                    return trim($decodedResponse['choices'][0]['message']['content']);
                }

                throw new Exception("HTTP status code: " . $httpCode);
            } catch (Exception $e) {
                $retries++;
                sleep($waitTime);
                $waitTime *= 2;
            }
        }

        return "";
    }
}
