# Kurage 訪問看護ナビ（khokan）

訪問看護ステーションの名前で検索してきた人（ケアマネジャーや病院に名前を渡された家族）に、その1軒のことを公開データで答える。**PHP 1ファイル＋SQLite**。ポート・デーモン・プロキシは要らず、レンタルサーバーに置けば動く。

公開デモ: https://kurage.exbridge.jp/khokan.php/

## できること

| 画面 | URL | 内容 |
|---|---|---|
| 入口 | `/khokan.php/` | 住所から探す・同じ名前の多いステーション |
| 事業所ページ | `/khokan.php/s/<事業所番号>` | 住所・電話・利用可能曜日・運営法人・公式サイト・地図リンク。同じ名前の別事業所を住所で見分ける |
| 同名一覧 | `/khokan.php/n/<名前>` | 「ひまわり」49軒などを都道府県別に |
| 都道府県→市区町村 | `/khokan.php/area/<都道府県コード>/[市区町村]` | 件数つき |
| 住所から近い順 | `/khokan.php/near?q=<住所>` | 国土地理院の住所検索で座標化し直線距離順。日曜・土曜で絞る |
| 制度の引き表 | `/khokan.php/seido/` | 医療保険か介護保険か、要介護度別の区分支給限度基準額 |
| 厚生局への届出 | 事業所ページ内 | 24時間対応体制・精神科訪問看護・特別管理加算の届出有無（名簿を取り込んだ地域のみ） |
| sitemap / llms.txt | `/khokan.php/sitemap.xml` `/khokan.php/llms.txt` | 索引→47都道府県 |

**載せていないもの:** 空き状況・受け入れ可否・料金。どの公開データにも無いので、画面でも「電話で確認」と出している。

## 設置手順（レンタルサーバー）

1. `php/khokan.php` と `php/khokan_data/`（`khokan.sqlite` と `.htaccess`）を、公開ディレクトリ直下に置く。
2. 公開ディレクトリの `.htaccess` で PHP 8 を指定する（heteml の例: `AddHandler php-script .php`。既定のままだと PHP 5.6 で動く）。
3. `khokan_data/.htaccess` は `Require all denied` のまま置く（SQLite を直接ダウンロードさせない）。
4. `https://<あなたのドメイン>/khokan.php/` を開き、事業所ページ・住所検索・`khokan_data/khokan.sqlite` が 403 になることを確かめる。
5. 自分のドメインに合わせて `khokan.php` 冒頭の `SITE` と `BASE`、OGP 画像のパスを直す。

要件: PHP 8.1 以上、PDO SQLite、curl（住所検索に使う）。DB サーバーは要らない。

## AI 向け設置指示書

Claude Code / Codex などに渡す指示:

```
/home/kojima/work/khokan（またはこの zip を展開したフォルダ）を、
<公開ディレクトリ> に設置してください。
- php/khokan.php と php/khokan_data/ をそのままコピー
- .htaccess で PHP 8 を指定（AddHandler php-script .php）
- khokan.php の SITE / BASE を <https://自分のドメイン> に書き換え
- 設置後、/khokan.php/ が 200、/khokan.php/s/0000000000 が 404、
  /khokan_data/khokan.sqlite が 403 になることを curl で確認して報告
```

## データの更新（手元で作って置き換える）

半年に1回、次の順で `khokan.sqlite` を作り直して置き換える。

```bash
# 1) 厚労省 介護サービス情報公表システム オープンデータ「130_訪問看護」CSV を落とす
#    https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html （6月末・12月末時点）
/usr/bin/python3 scripts/build_db.py data/jigyosho_130_all_YYYYMMDDhhmmss.csv

# 2) 地方厚生局「届出受理指定訪問看護事業所名簿」Excel を落として突き合わせる（任意）
#    東海北陸: https://kouseikyoku.mhlw.go.jp/tokaihokuriku/newpage_00245.html
/usr/bin/python3 scripts/parse_kouseikyoku.py <名簿.xlsx …> --write \
    --bureau 東海北陸厚生局 --bureau-url <名簿ページのURL>

# 3) php/khokan_data/khokan.sqlite をサーバーへ置く
```

- `build_db.py` が数える。画面は数えない（件数・時点は DB の `meta` から出す）。
- 厚生局の名簿と厚労省 CSV には共通キーが無いので、**電話番号で突き合わせ**、電話で決まらないものは名前＋市区町村で補う。どちらでも決まらない事業所は結び付けない。
- 他の厚生局（北海道・近畿・九州など）も同じ形式で公開している。`--bureau` と `--bureau-url` を変えて足す。

## 出典と利用条件（画面の脚注に出している）

- 厚生労働省「介護サービス情報公表システム オープンデータ（130_訪問看護）」を加工して作成。公共データ利用規約（第1.0版）。出典表示と「加工して作成」の表示が必要。
- 東海北陸厚生局ホームページ「届出受理指定訪問看護事業所名簿」を加工して作成。公共データ利用規約（第1.0版）。
- 住所検索: 国土地理院 地名検索 API。
- 介護サービス情報公表システムの**事業所別ページ（Web）は使っていない**。同サイトの転載規定に「関係のない営利行為への利用を禁止」とあるため。

## ファイル

```
php/khokan.php              画面（1ファイル）
php/khokan_data/.htaccess   Require all denied
php/khokan_data/khokan.sqlite  build_db.py が作る（配布キットには最新版を同梱）
scripts/build_db.py         CSV → SQLite
scripts/parse_kouseikyoku.py 厚生局名簿 → notices
scripts/make_ogp.py         OGP 1200×630
```

ライセンス: MIT（同梱データの利用条件は上記の出典元に従う）。
