import { useEffect, useState, createElement } from '@wordpress/element';
import { createRoot } from 'react-dom/client';
import { Button, Notice, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getProduct, updateProduct } from './api/products';
import { ListingsEditor } from './components/ListingsEditor';
import { ExtrasEditor } from './components/ExtrasEditor';
import { StockStatusSelect } from './components/StockStatusSelect';

const PRODUCT_TYPE_OPTIONS = [
  { value: 'generic', label: __('汎用', 'affilicard') },
  { value: 'ebook', label: __('電子書籍', 'affilicard') },
];

export function MetaboxApp({ postId }) {
  const [data, setData] = useState(null);
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState(null);

  useEffect(() => {
    if (!postId) {
      return;
    }
    getProduct(postId)
      .then(setData)
      .catch(() =>
        setData({
          product_type: 'generic',
          stock_status: 'available',
          extras: [],
          listings: [],
        }),
      );
  }, [postId]);

  if (!postId) {
    return <p>{__('保存後に編集できます', 'affilicard')}</p>;
  }
  if (data === null) {
    return <p>{__('読み込み中…', 'affilicard')}</p>;
  }

  const update = (patch) => setData({ ...data, ...patch });

  const onSave = async () => {
    setSaving(true);
    setNotice(null);
    try {
      const next = await updateProduct(postId, {
        product_type: data.product_type,
        stock_status: data.stock_status,
        extras: data.extras,
        listings: data.listings,
      });
      setData(next);
      setNotice({
        type: 'success',
        message: __('保存しました', 'affilicard'),
      });
    } catch {
      setNotice({
        type: 'error',
        message: __('保存に失敗しました', 'affilicard'),
      });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="affilicard-metabox">
      {notice && (
        <Notice status={notice.type} onRemove={() => setNotice(null)}>
          {notice.message}
        </Notice>
      )}
      <SelectControl
        label={__('商品タイプ', 'affilicard')}
        value={data.product_type ?? 'generic'}
        options={PRODUCT_TYPE_OPTIONS}
        onChange={(v) => update({ product_type: v })}
      />
      <StockStatusSelect value={data.stock_status} onChange={(v) => update({ stock_status: v })} />
      <ExtrasEditor
        productType={data.product_type ?? 'generic'}
        extras={data.extras ?? []}
        onChange={(extras) => update({ extras })}
      />
      <ListingsEditor
        listings={data.listings ?? []}
        onChange={(listings) => update({ listings })}
      />
      <div className="affilicard-metabox-actions">
        <Button variant="primary" onClick={onSave} disabled={saving}>
          {saving ? __('保存中…', 'affilicard') : __('Affilicard データを保存', 'affilicard')}
        </Button>
      </div>
    </div>
  );
}

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('affilicard-metabox-root');
  if (!root) {
    return;
  }
  const postId = parseInt(root.dataset.postId ?? '0', 10) || 0;
  if (typeof createRoot === 'function') {
    createRoot(root).render(createElement(MetaboxApp, { postId }));
  }
});
