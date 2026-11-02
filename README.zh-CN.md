# pig-permission-gate

pig 编程助手的权限闸门。它挡在模型和 `bash` 之间：删除变成可以撤回的移动，危险命令等你点头，`rm -rf /` 永远不会执行。

[English](README.md)

## 安装

```bash
pig install git:github.com/owner888/pig-permission-gate
```

不需要别的，也没有 Composer 依赖。不装这个包，模型写什么 pig 就照原样执行什么。

## 它做什么

| 模型执行 | 结果 |
| --- | --- |
| `rm -rf /`、`rm -rf ~` | 直接拦下。 |
| `sudo …`、`dd if=`、`mkfs`、`git reset --hard`、`git push --force`、`git clean -fdx`、`chmod -R`、`shutdown` … | 等你确认；没人能确认的场合（print 模式、没有界面的 RPC）直接拦下。 |
| `rm`、`rm -r`、`rm -rf`、`sudo rm` | 改成 `trash`，移到废纸篓。没有 `trash` 命令的系统（macOS 14 以前、Linux、Termux）改为移到当前目录下的 `_to_delete/<时间>-<pid>/`。带 `-f` 的只移动存在的路径，`rm -f` 删不存在的东西照样成功。 |
| `rm -rf node_modules`、`vendor`、`dist`、`build`、`.cache`、`*.log` … | 照原样删：这些是构建产物和缓存。 |
| `adb shell rm …`、`ssh host "rm …"` | 照原样执行：文件在另一台机器上，本机的废纸篓管不到。 |

命令按 shell 的方式解析：`grep` 的匹配词、提交信息里出现的词不算命令；而 `"su""do"`、`/usr/bin/sudo`、`S=sudo; $S …`、`bash -c "sudo …"` 都照样认作 `sudo`。

`_to_delete/` 不会自动清空：自己看过再删；在 git 仓库里会出现在 `git status` 中，可以加进 `.gitignore`。

## 开发

测试依赖一份 pig 源码：

```bash
PIG_DIR=~/path/to/pig ~/path/to/pig/vendor/bin/phpunit -c phpunit.xml
```

`PIG_DIR` 默认是本包旁边的 `pig` 目录。

## 许可证

Apache-2.0，见 [LICENSE](LICENSE) 和 [NOTICE](NOTICE)。
