# es.bpclassgenerator

Bitrix24 module for generating PHP classes from Bizproc/robot templates and installing templates back from classes.

## Repository Layout

Repository root is the module root. After download/clone, it must be placed into:

`/local/modules/es.bpclassgenerator`

## Installation

1. Copy module directory to Bitrix project:
   - target path: `/local/modules/es.bpclassgenerator`
2. In Bitrix admin panel open `Marketplace -> Installed solutions`.
3. Find `es.bpclassgenerator` and click Install.

Or via git in project root:

```bash
git clone git@github.com:Zok15/es.bpclassgenerator.git local/modules/es.bpclassgenerator
```

## Update

```bash
cd local/modules/es.bpclassgenerator
git pull
```

Then run module update/reinstall only if required by release notes.

## Packaging for Download

To provide a downloadable archive:

```bash
tar -czf es.bpclassgenerator.tar.gz es.bpclassgenerator/
```

Users should extract it so module files end up in `/local/modules/es.bpclassgenerator`.

## Development

- Main entry points:
  - `install/index.php`
  - `options.php`
  - `lib/Service/*`
- Tests are located in `tests/`.

## License

Internal project module. Use according to your organization policy.
