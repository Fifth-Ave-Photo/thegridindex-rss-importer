# One-Time Setup

Follow these steps once. After that, everything deploys automatically.

## Step 1: Create the GitHub repository

1. Go to https://github.com/new
2. **Owner:** Fifth-Ave-Photo
3. **Repository name:** `thegridindex-rss-importer`
4. **Visibility:** Public (recommended) or Private (works either way)
5. **Do NOT** initialize with README, .gitignore, or license — this package has them
6. Click **Create repository**

## Step 2: Upload these files to the repo

**Easiest method — drag and drop via the GitHub website:**

1. On the new empty repo page, click **"uploading an existing file"** (the link in the quick-setup section)
2. Drag every file and folder from this package into the upload area
3. Important: GitHub's web uploader can't upload empty folders. Make sure `.github/workflows/`, `.wordpress-org/`, and `assets/` have files in them (they do).
4. Scroll down → Commit message: `Initial commit` → Click **Commit changes**

**Alternative — GitHub Desktop:**

1. Open GitHub Desktop → File → Clone repository → pick the empty repo
2. Copy this package's contents into the cloned folder
3. Commit and push

## Step 3: Get your WordPress.org application password

1. Log into wordpress.org
2. Go to https://wordpress.org/support/users/fifthavesupport/edit/ (or whichever account owns the plugin)
3. Scroll to **"Account Management"** → **"Application Passwords"**
4. Application name: `GitHub Actions`
5. Click **Add New Application Password**
6. **Copy the password immediately** — it's only shown once. It looks like `xxxx xxxx xxxx xxxx xxxx xxxx`

## Step 4: Add the secrets to your GitHub repo

1. On your GitHub repo page, click **Settings** (top tab, far right)
2. Left sidebar: **Secrets and variables** → **Actions**
3. Click **New repository secret**, add:
   - **Name:** `SVN_USERNAME`
   - **Secret:** your wordpress.org username (e.g. `fifthavesupport`)
4. Click **New repository secret** again, add:
   - **Name:** `SVN_PASSWORD`
   - **Secret:** the application password from Step 3 (spaces are okay)

## Step 5: Test the assets workflow

The assets workflow only runs when files in `.wordpress-org/` change, so let's trigger it manually:

1. Go to the **Actions** tab on your repo
2. Click **"Deploy Assets to WordPress.org"** in the left sidebar
3. Click **Run workflow** (right side) → **Run workflow**
4. Wait ~30 seconds. Green checkmark = success.

Since `.wordpress-org/` is empty right now, this won't change anything visible. But a successful run confirms your secrets and workflow are correct.

## Step 6: Add your plugin page assets (when ready)

When you have your banner, icon, and screenshots ready:

1. Name them per WordPress.org conventions:
   - `banner-1544x500.png`
   - `banner-772x250.png` (optional)
   - `icon-256x256.png`
   - `icon-128x128.png` (optional)
   - `screenshot-1.png`, `screenshot-2.png`, etc.
2. Upload them to the `.wordpress-org/` folder in your GitHub repo
3. Commit → the workflow runs automatically
4. WordPress.org updates within ~15 minutes

## Step 7: Deploy the plugin itself

When you're ready to push version 1.0.74 to WordPress.org:

1. On your repo, click **Releases** (right sidebar) → **Create a new release**
2. Click **Choose a tag** → type `1.0.74` → **Create new tag: 1.0.74 on publish**
3. **Release title:** `1.0.74`
4. **Description:** paste your changelog notes (optional)
5. Click **Publish release**
6. Watch the Actions tab — the deploy workflow runs automatically
7. WordPress.org's plugin page updates within ~15 minutes

## Future updates

For every new version:

1. Edit `thegridindex-rss-importer.php` — update the version number in the header
2. Edit `readme.txt` — update `Stable tag:` and add changelog entry
3. Commit and push to `main`
4. Create a GitHub Release with the new version number as the tag
5. Done — deployment is automatic

You never need to touch SVN, Cornerstone, or TortoiseSVN again.

## Troubleshooting

**Workflow failed with auth error**
- Your application password might have been regenerated or you copied it wrong. Generate a new one and update `SVN_PASSWORD` in repo secrets.

**Workflow says "tag already exists"**
- You're trying to deploy a version that's already on WordPress.org. Bump the version number in `thegridindex-rss-importer.php` and `readme.txt`, then create a new release.

**Plugin page on WordPress.org isn't updating**
- Wait the full 15 minutes — the WordPress.org CDN caches aggressively. If still not updated after 30 minutes, check the Actions log for errors.

**Stable tag mismatch warning**
- The `Stable tag:` line in `readme.txt` must match a tag that exists in SVN. If you bump the version but don't create a release with that tag number, this can break installs.
