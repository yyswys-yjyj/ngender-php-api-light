# ngender-php-API-light
## 概述
这是[ngender-php-api](https://github.com/yyswys-yjyj/ngender-php-api)的轻量版本，原版均来自[observerss/ngender](https://github.com/observerss/ngender)
如果你想做多机部署，你可以看看[ngender-php-api-webcore](https://github.com/yyswys-yjyj/ngender-php-api-webcore)
## 功能
|功能|效果|说明|
|-|-|-|
|仅API|80/443端口访问|相较于完整版，该版本去掉了web访问|
|防XSS注入攻击|防攻击|如果你有WAF最好开启，防XSS是AI写的，我只是实践者，不是项目开发者||
|娱乐性功能|这个项目提供了4种功能|你可以通过这些功能，配合“分享”来整蛊你的朋友|
|自定义映射表|能够手动指定一个名字所对应的结果，优先级比任何模式都要大|这个功能是为了避免一些众所周知的问题，也能实现更正原项目中“胜男”的问题|
## API简述
### 端点
例如你把文件放在了`/api/v1/gender/`里，则你的接口和端点为：`http://example.com/api/v1/gender/`   
与完整版不同的是，构建URL时，末尾的斜杠不能去掉，例如：
```bash
curl http://example.com/api/v1/gender/?name=测试
```
如果你给index改了名，则可能需要这样：
```bash
curl http://example.com/api.php?name=测试
```
### 请求参数(Query)

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| `name` | string | 是 | 无 | 用于猜测性别的姓名，支持纯中文字符（默认长度 2-4 个字符） |
| `method` | integer | 否 | `0` | API 模式：<br>`0` 正常（原始算法）<br>`1` 反转性别（对概率取反并）<br>`2` 反向性别（交换男女得分）<br>`3` 随机模式（随机返回性别和概率 0.5-1） |
| `nolimit` | string | 否 | `false` | 是否取消姓名字数限制。支持值：`1`、`on`、`true`（不区分大小写）。启用后姓名可为任意长度的纯中文字符。 |
| `mapping` | string (JSON) | 否 | 无 | 自定义姓名映射表，JSON 字符串格式。优先级高于算法。支持两种格式：<br>• 对象格式：`{"张三":{"gender":"male","min":0.8,"max":0.95}}`<br>• 索引数组格式：`{"李四":["female",0.9,1]}`<br>概率范围必须 `0.5 ≤ min ≤ max ≤ 1`。 |
| `debug` | integer | 否 | `0` | 是否返回调试信息。`1` 开启，`0` 关闭。开启后返回字段 `debug_info`，包含请求参数、映射表解析过程、随机数生成值、概率计算中间结果等（不包含随机到的趣味文案）。 |

> 虽然文档只提到了GET方法，但也支持POST和OPTIONS 

## 部署
### 安装
部署在安装了php 7.0+并带有mbstring的服务器，异步项目
## 项目说明
该项目搬自[observerss大佬写的ngender](https://github.com/observerss/ngender)，结合AI把它改成了可网页访问、API调用的php版本
