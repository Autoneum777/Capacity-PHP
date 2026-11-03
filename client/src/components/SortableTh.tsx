import type { CSSProperties, ReactNode } from 'react';
import { useI18n } from '../context/I18nContext';
import { SORTABLE_TH_BASE, sortIndicator, type SortDirection } from '../utils/tableSort';

type Props = {
  label: ReactNode;
  active: boolean;
  direction: SortDirection;
  onClick: () => void;
  title?: string;
  style?: CSSProperties;
  className?: string;
};

export default function SortableTh({ label, active, direction, onClick, title, style, className }: Props) {
  const { t } = useI18n();
  return (
    <th
      className={className}
      style={{ ...SORTABLE_TH_BASE, ...style }}
      onClick={onClick}
      title={title ?? t('common.sortClickTitle')}
    >
      {label}
      {sortIndicator(active, direction)}
    </th>
  );
}
