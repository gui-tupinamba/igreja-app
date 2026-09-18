import { useEffect, useRef, useState } from "react";
import Cropper, { type Area } from "react-easy-crop";
import { Crop, ImagePlus, Trash2, ZoomIn } from "lucide-react";
import { Modal } from "./ui";

const ASPECT = 3770 / 1200;
const MAX_IMAGES = 5;
const MAX_SIZE = 5 * 1024 * 1024;

type Props = {
  value: File[];
  onChange: (files: File[]) => void;
};

export function ContentImagePicker({ value, onChange }: Props) {
  const inputRef = useRef<HTMLInputElement>(null);

  const [editingIndex, setEditingIndex] = useState<number | null>(null);

  const [error, setError] = useState<string | null>(null);

  function addFiles(files: File[]) {
    setError(null);

    const allowed = ["image/jpeg", "image/png", "image/webp"];

    for (const file of files) {
      if (!allowed.includes(file.type)) {
        setError("Use apenas imagens JPEG, PNG ou WebP.");
        return;
      }

      if (file.size > MAX_SIZE) {
        setError(`A imagem "${file.name}" possui mais de 5 MB.`);
        return;
      }
    }

    if (value.length + files.length > MAX_IMAGES) {
      setError("Você pode selecionar no máximo 5 imagens.");
      return;
    }

    onChange([...value, ...files]);
  }

  function removeImage(index: number) {
    onChange(value.filter((_, currentIndex) => currentIndex !== index));
  }

  function replaceImage(index: number, file: File) {
    onChange(
      value.map((current, currentIndex) =>
        currentIndex === index ? file : current,
      ),
    );
  }

  return (
    <div className="content-image-picker">
      <input
        ref={inputRef}
        className="image-picker-input"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
        onChange={(event) => {
          const files = Array.from(event.target.files ?? []);

          addFiles(files);

          event.target.value = "";
        }}
      />

      <button
        type="button"
        className="image-upload-box"
        onClick={() => inputRef.current?.click()}
      >
        <span className="image-upload-icon">
          <ImagePlus size={28} />
        </span>

        <span className="image-upload-text">
          <strong>Adicionar imagens</strong>

          <span>Clique para escolher até 5 imagens</span>

          <small>JPEG, PNG ou WebP · máximo 5 MB</small>
        </span>

        <span className="image-upload-button">Escolher imagens</span>
      </button>

      {error && <p className="image-picker-error">{error}</p>}

      {value.length > 0 && (
        <>
          <div className="image-picker-header">
            <div>
              <strong>Prévia das imagens</strong>

              <span>
                {value.length} de {MAX_IMAGES}
              </span>
            </div>

            <small>O enquadramento final será panorâmico 3770 × 1200.</small>
          </div>

          <div className="image-preview-list">
            {value.map((file, index) => (
              <ImagePreview
                key={`${file.name}-${file.lastModified}-${index}`}
                file={file}
                index={index}
                onCrop={() => setEditingIndex(index)}
                onRemove={() => removeImage(index)}
              />
            ))}
          </div>
        </>
      )}

      {editingIndex !== null && value[editingIndex] && (
        <ImageCropEditor
          file={value[editingIndex]}
          onClose={() => setEditingIndex(null)}
          onSave={(file) => {
            replaceImage(editingIndex, file);

            setEditingIndex(null);
          }}
        />
      )}
    </div>
  );
}

function ImagePreview({
  file,
  index,
  onCrop,
  onRemove,
}: {
  file: File;
  index: number;
  onCrop: () => void;
  onRemove: () => void;
}) {
  const [src, setSrc] = useState("");

  useEffect(() => {
    const url = URL.createObjectURL(file);

    setSrc(url);

    return () => {
      URL.revokeObjectURL(url);
    };
  }, [file]);

  return (
    <article className="image-preview-card">
      <div className="image-preview-frame">
        {src && <img src={src} alt={`Imagem ${index + 1}`} />}

        {index === 0 && <span className="image-cover-badge">Capa</span>}
      </div>

      <div className="image-preview-info">
        <div>
          <strong>Imagem {index + 1}</strong>

          <span>{formatBytes(file.size)}</span>
        </div>

        <div className="image-preview-actions">
          <button type="button" onClick={onCrop}>
            <Crop size={16} />
            Ajustar corte
          </button>

          <button
            type="button"
            className="danger-text"
            onClick={onRemove}
            aria-label="Remover imagem"
          >
            <Trash2 size={16} />
          </button>
        </div>
      </div>
    </article>
  );
}

function ImageCropEditor({
  file,
  onClose,
  onSave,
}: {
  file: File;
  onClose: () => void;
  onSave: (file: File) => void;
}) {
  const [src, setSrc] = useState("");

  const [crop, setCrop] = useState({
    x: 0,
    y: 0,
  });

  const [zoom, setZoom] = useState(1);

  const [croppedAreaPixels, setCroppedAreaPixels] = useState<Area | null>(null);

  const [processing, setProcessing] = useState(false);

  useEffect(() => {
    const url = URL.createObjectURL(file);

    setSrc(url);

    return () => {
      URL.revokeObjectURL(url);
    };
  }, [file]);

  async function save() {
    if (!croppedAreaPixels) {
      return;
    }

    setProcessing(true);

    try {
      const cropped = await createCroppedImage(
        src,
        croppedAreaPixels,
        file.name,
      );

      onSave(cropped);
    } finally {
      setProcessing(false);
    }
  }

  return (
    <Modal title="Ajustar imagem" onClose={onClose}>
      <div className="crop-editor">
        <p className="hint">
          Arraste a imagem para escolher o enquadramento que será publicado.
        </p>

        <div className="crop-area">
          {src && (
            <Cropper
              image={src}
              crop={crop}
              zoom={zoom}
              minZoom={1}
              maxZoom={3}
              aspect={ASPECT}
              cropShape="rect"
              showGrid={true}
              restrictPosition={true}
              objectFit="contain"
              onCropChange={setCrop}
              onZoomChange={setZoom}
              onCropComplete={(_, pixels) => {
                setCroppedAreaPixels(pixels);
              }}
            />
          )}
        </div>

        <div className="crop-zoom">
          <ZoomIn size={18} />

          <input
            type="range"
            min={1}
            max={3}
            step={0.01}
            value={zoom}
            onChange={(event) => setZoom(Number(event.target.value))}
          />
        </div>

        <div className="crop-preview-label">
          <strong>Formato final</strong>

          <span>3770 × 1200 · panorâmico</span>
        </div>

        <div className="form-actions">
          <button type="button" onClick={onClose}>
            Cancelar
          </button>

          <button
            type="button"
            className="primary"
            disabled={processing || !croppedAreaPixels}
            onClick={save}
          >
            {processing ? "Processando…" : "Aplicar corte"}
          </button>
        </div>
      </div>
    </Modal>
  );
}

async function createCroppedImage(
  src: string,
  area: Area,
  originalName: string,
): Promise<File> {
  const image = await loadImage(src);

  const canvas = document.createElement("canvas");

  canvas.width = 3770;
  canvas.height = 1200;

  <p className="crop-help">
    A área dentro da grade representa exatamente a imagem que será publicada. O
    resultado final será 3770 × 1200.
  </p>;

  const context = canvas.getContext("2d");

  if (!context) {
    throw new Error("Não foi possível processar a imagem.");
  }

  context.drawImage(
    image,
    area.x,
    area.y,
    area.width,
    area.height,
    0,
    0,
    canvas.width,
    canvas.height,
  );

  const blob = await new Promise<Blob>((resolve, reject) => {
    canvas.toBlob(
      (result) => {
        if (result) {
          resolve(result);
        } else {
          reject(new Error("Não foi possível gerar a imagem."));
        }
      },
      "image/webp",
      0.88,
    );
  });

  const baseName = originalName.replace(/\.[^.]+$/, "");

  return new File([blob], `${baseName}.webp`, {
    type: "image/webp",
    lastModified: Date.now(),
  });
}

function loadImage(src: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();

    image.onload = () => resolve(image);

    image.onerror = () => reject(new Error("Não foi possível abrir a imagem."));

    image.src = src;
  });
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
