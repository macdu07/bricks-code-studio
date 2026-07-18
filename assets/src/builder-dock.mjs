export function bottomDockOffset(rect, viewportHeight, visible = true) {
  if (!visible || !rect || viewportHeight <= 0 || rect.width <= 0 || rect.height <= 0) return 0;
  if (rect.height > 180 || rect.width <= rect.height * 2) return 0;
  const touchesViewportBottom = rect.bottom >= viewportHeight - 2;
  const startsInsideViewport = rect.top >= 0 && rect.top < viewportHeight;
  if (!touchesViewportBottom || !startsInsideViewport) return 0;
  return Math.max(0, Math.ceil(viewportHeight - rect.top));
}
