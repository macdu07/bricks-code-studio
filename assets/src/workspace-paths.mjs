export function extensionForFolder(folderPath = '') {
  const rootFolder = folderPath.replace(/^\/+|\/+$/g, '').split('/')[0].toLowerCase();
  return ['scss', 'css', 'js'].includes(rootFolder) ? rootFolder : 'scss';
}

export function resolveNewFilePath(folderPath, input) {
  const folder = (folderPath || 'scss').replace(/^\/+|\/+$/g, '');
  const extension = extensionForFolder(folder);
  let value = input.trim().replace(/^\/+|\/+$/g, '');
  if (!value) return '';
  if (!/\.[a-z0-9]+$/i.test(value)) value += `.${extension}`;
  if (!value.includes('/') || !/^(scss|css|js)\//i.test(value)) value = `${folder}/${value}`;
  if (!value.toLowerCase().endsWith(`.${extension}`)) throw new Error(`Files inside ${folder} must use the .${extension} extension.`);
  return value;
}
