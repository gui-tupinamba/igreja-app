import { useState } from "react";

import { Pressable, StyleSheet, Text, View } from "react-native";

import { Ionicons } from "@expo/vector-icons";

import { Image } from "expo-image";

import * as ImagePicker from "expo-image-picker";

import { colors } from "./theme";

export type SelectedPostImage = ImagePicker.ImagePickerAsset;

const MAX_IMAGES = 5;

const MAX_SIZE = 5 * 1024 * 1024;

const ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp"];

export function postImageMimeType(image: SelectedPostImage): string | null {
  if (image.mimeType && ALLOWED_TYPES.includes(image.mimeType)) {
    return image.mimeType;
  }

  const fileName = image.fileName?.toLowerCase() || image.uri.toLowerCase();

  if (fileName.endsWith(".jpg") || fileName.endsWith(".jpeg")) {
    return "image/jpeg";
  }

  if (fileName.endsWith(".png")) {
    return "image/png";
  }

  if (fileName.endsWith(".webp")) {
    return "image/webp";
  }

  return null;
}

export function postImageFileName(
  image: SelectedPostImage,
  index: number,
): string {
  if (image.fileName) {
    return image.fileName;
  }

  const mime = postImageMimeType(image);

  const extension =
    mime === "image/png" ? "png" : mime === "image/webp" ? "webp" : "jpg";

  return `publicacao-${Date.now()}-${index + 1}.${extension}`;
}

export function PostImagePicker({
  value,
  onChange,
  disabled = false,
}: {
  value: SelectedPostImage[];
  onChange: (images: SelectedPostImage[]) => void;
  disabled?: boolean;
}) {
  const [error, setError] = useState<string | null>(null);

  async function pickImages() {
    if (disabled) {
      return;
    }

    if (value.length >= MAX_IMAGES) {
      setError("Você já selecionou o limite de 5 imagens.");

      return;
    }

    setError(null);

    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ["images"],

      allowsMultipleSelection: true,

      selectionLimit: MAX_IMAGES - value.length,

      orderedSelection: true,

      quality: 1,
    });

    if (result.canceled) {
      return;
    }

    const selected = result.assets;

    for (const image of selected) {
      const mime = postImageMimeType(image);

      if (!mime) {
        setError("Utilize apenas imagens JPEG, PNG ou WebP.");

        return;
      }

      if (image.fileSize !== undefined && image.fileSize > MAX_SIZE) {
        setError(
          `A imagem "${image.fileName || "selecionada"}" possui mais de 5 MB.`,
        );

        return;
      }
    }

    const combined = [...value, ...selected];

    if (combined.length > MAX_IMAGES) {
      setError("Você pode selecionar no máximo 5 imagens.");

      return;
    }

    onChange(combined);
  }

  function removeImage(index: number) {
    onChange(value.filter((_, current) => current !== index));

    setError(null);
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Imagens</Text>

      <Text style={styles.help}>
        Opcional · até 5 imagens · JPEG, PNG ou WebP · máximo 5 MB cada
      </Text>

      <Pressable
        accessibilityRole="button"
        disabled={disabled || value.length >= MAX_IMAGES}
        onPress={pickImages}
        style={({ pressed }) => [
          styles.addButton,

          (disabled || value.length >= MAX_IMAGES) && styles.disabled,

          pressed && !disabled && styles.pressed,
        ]}
      >
        <View style={styles.addIcon}>
          <Ionicons name="images-outline" size={25} color={colors.green} />
        </View>

        <View style={styles.addContent}>
          <Text style={styles.addTitle}>Adicionar imagens</Text>

          <Text style={styles.addDescription}>Escolha fotos da galeria</Text>
        </View>

        <Ionicons name="add-circle-outline" size={24} color={colors.green} />
      </Pressable>

      {error ? <Text style={styles.error}>{error}</Text> : null}

      {value.length > 0 ? (
        <View style={styles.previewHeader}>
          <Text style={styles.previewTitle}>Imagens selecionadas</Text>

          <Text style={styles.count}>
            {value.length} / {MAX_IMAGES}
          </Text>
        </View>
      ) : null}

      {value.map((image, index) => (
        <View key={`${image.uri}-${index}`} style={styles.imageCard}>
          <View style={styles.imageFrame}>
            <Image
              source={{
                uri: image.uri,
              }}
              contentFit="cover"
              style={styles.image}
            />

            {index === 0 ? (
              <View style={styles.coverBadge}>
                <Text style={styles.coverText}>Capa</Text>
              </View>
            ) : null}
          </View>

          <View style={styles.imageInfo}>
            <View style={styles.imageText}>
              <Text style={styles.imageTitle}>Imagem {index + 1}</Text>

              <Text numberOfLines={1} style={styles.fileName}>
                {image.fileName || "Imagem selecionada"}
              </Text>

              {image.fileSize ? (
                <Text style={styles.fileSize}>
                  {formatBytes(image.fileSize)}
                </Text>
              ) : null}
            </View>

            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`Remover imagem ${index + 1}`}
              disabled={disabled}
              onPress={() => removeImage(index)}
              style={({ pressed }) => [
                styles.removeButton,

                pressed && styles.pressed,
              ]}
            >
              <Ionicons name="trash-outline" size={19} color={colors.danger} />
            </Pressable>
          </View>
        </View>
      ))}
    </View>
  );
}

function formatBytes(bytes: number) {
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const styles = StyleSheet.create({
  container: {
    marginBottom: 20,
  },

  title: {
    color: colors.ink,
    fontSize: 14,
    fontWeight: "800",
    marginBottom: 4,
  },

  help: {
    color: colors.muted,
    fontSize: 12,
    lineHeight: 17,
    marginBottom: 11,
  },

  addButton: {
    minHeight: 76,
    flexDirection: "row",
    alignItems: "center",
    gap: 12,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 13,
    padding: 12,
    backgroundColor: colors.white,
  },

  addIcon: {
    width: 46,
    height: 46,
    borderRadius: 12,
    backgroundColor: colors.greenSoft,
    alignItems: "center",
    justifyContent: "center",
  },

  addContent: {
    flex: 1,
  },

  addTitle: {
    color: colors.ink,
    fontWeight: "800",
    marginBottom: 3,
  },

  addDescription: {
    color: colors.muted,
    fontSize: 12,
  },

  error: {
    color: colors.danger,
    fontSize: 12,
    lineHeight: 17,
    marginTop: 8,
    fontWeight: "700",
  },

  previewHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginTop: 17,
    marginBottom: 9,
  },

  previewTitle: {
    color: colors.ink,
    fontWeight: "800",
  },

  count: {
    color: colors.muted,
    fontSize: 12,
    fontWeight: "700",
  },

  imageCard: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: 13,
    overflow: "hidden",
    marginBottom: 10,
  },

  imageFrame: {
    position: "relative",
  },

  image: {
    width: "100%",
    height: 115,
    backgroundColor: colors.greenSoft,
  },

  coverBadge: {
    position: "absolute",
    top: 8,
    left: 8,
    backgroundColor: colors.green,
    borderRadius: 999,
    paddingHorizontal: 9,
    paddingVertical: 4,
  },

  coverText: {
    color: colors.white,
    fontSize: 10,
    fontWeight: "800",
  },

  imageInfo: {
    flexDirection: "row",
    alignItems: "center",
    padding: 11,
    gap: 10,
  },

  imageText: {
    flex: 1,
  },

  imageTitle: {
    color: colors.ink,
    fontWeight: "800",
    marginBottom: 2,
  },

  fileName: {
    color: colors.muted,
    fontSize: 11,
  },

  fileSize: {
    color: colors.muted,
    fontSize: 11,
    marginTop: 2,
  },

  removeButton: {
    width: 42,
    height: 42,
    borderRadius: 10,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: "#fff1f1",
  },

  disabled: {
    opacity: 0.45,
  },

  pressed: {
    opacity: 0.7,
  },
});
