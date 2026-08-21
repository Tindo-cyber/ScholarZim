import type { ReactNode } from 'react';

export interface Column<T> {
  /** Column heading. */
  header: string;
  /** Cell renderer — receives the whole row so it can compose links and badges. */
  cell: (row: T) => ReactNode;
  /** Optional fixed width, e.g. "120px" or "20%". */
  width?: string;
}

/**
 * Generic data table. The wrapper scrolls horizontally on its own so a wide
 * table never forces the whole page to scroll sideways on mobile.
 */
export default function Table<T>({
  columns,
  rows,
  rowKey,
  caption,
}: {
  columns: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string | number;
  /** Screen-reader description of what the table contains. */
  caption?: string;
}) {
  return (
    <div className="sz-table-wrap">
      <table className="sz-table">
        {caption && <caption className="sz-visually-hidden">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.header} scope="col" style={column.width ? { width: column.width } : undefined}>
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((column) => (
                <td key={column.header}>{column.cell(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
