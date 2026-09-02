import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

// Standard shadcn-vue cn() helper — merges conditional class lists
// (clsx) and resolves conflicting Tailwind utility classes (twMerge).
export function cn(...inputs) {
  return twMerge(clsx(inputs));
}
